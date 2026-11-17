"""FedAvg + top-k sparsification with error feedback under a per-client battery.

One run = one (policy, seed). Every client holds a battery b_i; each upload of a
top-k update costs E(s(k)) joules under the LTE radio model in energy.py. The
policy decides, per client and per round, whether to upload and with which k.

Policies
--------
fixed       k = rho * d every round until the battery cannot pay for it.
blind       energy-aware, but allocates with the tail-free model E = e_byte * s
            (the true radio still charges promotion + tail).
greedy      the per-round program  max_k delta(k) s.t. E(s(k)) <= A_r  solved
            literally: upload as soon as any k >= 1 is feasible.
mvt         proposed: upload only when the banked allowance A_r pays for the
            contraction-per-joule optimum k* (measured on the client's own
            update), then send the largest affordable k.
dos         "dense or silent": mvt with the worst-case model delta = k/d,
            for which k* = d.
mvtcap      mvt that never sends more than k*: surplus allowance is left
            unspent instead of being turned into a denser upload.

A_r is a token bucket: every client accrues b_i / R joules per round and A_r is
what it has accrued and not yet spent.
"""
import argparse
import json
import time
from pathlib import Path

import numpy as np
import torch
import torch.nn as nn
import torch.nn.functional as F

import energy as en

BATTERY_J = 4000e-3 * 3.85 * 3600.0  # 4000 mAh at 3.85 V nominal = 55,440 J
MEAN = np.array([0.4914, 0.4822, 0.4465], dtype=np.float32)
STD = np.array([0.2470, 0.2435, 0.2616], dtype=np.float32)


class SmallCNN(nn.Module):
    """conv16-pool-conv32-pool-fc256-fc10, no normalisation layers (d = 532,202)."""

    def __init__(self):
        super().__init__()
        self.c1 = nn.Conv2d(3, 16, 3, padding=1)
        self.c2 = nn.Conv2d(16, 32, 3, padding=1)
        self.f1 = nn.Linear(32 * 8 * 8, 256)
        self.f2 = nn.Linear(256, 10)

    def forward(self, x):
        x = F.max_pool2d(F.relu(self.c1(x)), 2)
        x = F.max_pool2d(F.relu(self.c2(x)), 2)
        x = F.relu(self.f1(x.flatten(1)))
        return self.f2(x)


def load_cifar(path):
    z = np.load(path)

    def prep(x):
        x = (x.astype(np.float32) / 255.0 - MEAN) / STD
        return torch.from_numpy(np.ascontiguousarray(x.transpose(0, 3, 1, 2)))

    return (prep(z["x_train"]), torch.from_numpy(z["y_train"]),
            prep(z["x_test"]), torch.from_numpy(z["y_test"]))


def dirichlet_partition(y, n_clients, alpha, rng):
    """Class-wise Dirichlet(alpha) split; alpha <= 0 means IID."""
    n = len(y)
    if alpha <= 0:
        return [np.sort(p) for p in np.array_split(rng.permutation(n), n_clients)]
    parts = [[] for _ in range(n_clients)]
    for c in range(int(y.max()) + 1):
        idx = rng.permutation(np.where(y == c)[0])
        cuts = (np.cumsum(rng.dirichlet(alpha * np.ones(n_clients)))[:-1] * len(idx)).astype(int)
        for i, chunk in enumerate(np.split(idx, cuts)):
            parts[i] += chunk.tolist()
    return [np.sort(np.array(p, dtype=np.int64)) for p in parts]


def flat(model):
    return torch.cat([p.detach().reshape(-1) for p in model.parameters()])


def load_flat(model, vec):
    torch.nn.utils.vector_to_parameters(vec, model.parameters())


@torch.no_grad()
def evaluate(model, x, y, bs=2000):
    model.eval()
    correct = 0
    for i in range(0, len(x), bs):
        correct += (model(x[i:i + bs]).argmax(1) == y[i:i + bs]).sum().item()
    return correct / len(x)


def local_update(model, w, x, y, idx, steps, bs, lr, gen):
    """Run `steps` minibatch-SGD steps from w on one client's data; return w_local - w."""
    load_flat(model, w.clone())
    model.train()
    opt = torch.optim.SGD(model.parameters(), lr=lr)
    for _ in range(steps):
        b = idx[torch.randint(len(idx), (bs,), generator=gen)]
        opt.zero_grad(set_to_none=True)
        F.cross_entropy(model(x[b]), y[b]).backward()
        opt.step()
    return flat(model) - w


def run(a):
    torch.set_num_threads(a.threads)
    rng = np.random.default_rng(a.seed)
    torch.manual_seed(a.seed)

    xtr, ytr, xte, yte = load_cifar(a.data)
    parts = [torch.from_numpy(p) for p in dirichlet_partition(ytr.numpy(), a.clients, a.alpha, rng)]
    n_i = np.array([len(p) for p in parts], dtype=np.float64)

    model = SmallCNN()
    w = flat(model).clone()
    d = w.numel()

    radio = en.lte_model(tail=not a.no_tail)        # what the battery is charged
    radio_blind = en.lte_model(tail=False)          # what the "blind" policy believes
    N, R = a.clients, a.rounds

    # Heterogeneous batteries: state of charge ~ U[soc_min, 1]; FL may spend a
    # fixed fraction of the charge present at the start of training.
    soc = rng.uniform(a.soc_min, 1.0, size=N)
    b0 = a.fl_fraction * BATTERY_J * soc
    if a.unconstrained:
        b0 = np.full(N, np.inf)
    battery = b0.copy()
    allowance = b0 / R                               # joules accrued per round
    bank = np.zeros(N)                               # policy ledger (token bucket)

    k_fixed = max(1, int(round(a.rho * d)))
    kstar = np.full(N, -1, dtype=np.int64)           # -1 = not yet measured
    if a.policy == "dos":
        kstar[:] = d

    grid = en.k_grid(d, 400)
    grid_t = torch.from_numpy(grid - 1)
    err = torch.zeros(N, d)                          # error-feedback memory

    uploads = np.zeros(N, dtype=np.int64)
    sum_delta = np.zeros(N)
    sum_k = np.zeros(N)
    energy_used = np.zeros(N)
    bytes_sent = np.zeros(N)
    last_upload = np.full(N, -1, dtype=np.int64)
    upload_rounds = [[] for _ in range(N)]
    kstar_log, curve_sum, curve_n, curve_by_round = [], np.zeros(len(grid)), 0, {}
    log = dict(round=[], acc=[], energy=[], participants=[], affordable=[], mean_rho=[], mean_delta=[])
    t0 = time.time()

    def can_afford_next(i):
        """Can client i still pay for the smallest upload its policy would send?"""
        if a.policy == "fixed":
            k_min = k_fixed
        elif a.policy in ("mvt", "dos", "mvtcap") and kstar[i] > 0:
            k_min = int(kstar[i])
        else:
            k_min = 1
        return battery[i] >= float(en.upload_energy(k_min, d, radio))

    for r in range(R):
        bank += allowance
        agg = torch.zeros(d)
        wsum, n_part, rhos, deltas = 0.0, 0, [], []
        curve_r = np.zeros(len(grid))

        for i in range(N):
            # ---- decide before training (no radio, no compute if silent) ----
            calibrating = False
            if a.policy == "fixed":
                k = k_fixed
            elif a.policy == "blind":
                k = max(1, en.max_k_within(bank[i], d, radio_blind))
            elif a.policy == "greedy":
                k = en.max_k_within(min(bank[i], battery[i]), d, radio)
            elif a.policy in ("mvt", "dos", "mvtcap"):
                if kstar[i] < 0:
                    calibrating, k = True, 0         # first pass: measure delta(k)
                elif bank[i] >= float(en.upload_energy(int(kstar[i]), d, radio)):
                    k = en.max_k_within(min(bank[i], battery[i]), d, radio)
                else:
                    k = 0
            else:
                raise ValueError(a.policy)
            if not calibrating and (k < 1 or battery[i] < float(en.upload_energy(k, d, radio))):
                continue

            # ---- local training + error feedback ----
            gen = torch.Generator().manual_seed(1_000_003 * a.seed + 1009 * r + i)
            delta_w = local_update(model, w, xtr, ytr, parts[i], a.local_steps, a.batch, a.lr, gen)
            p = err[i] + delta_w
            sq, order = torch.sort(p * p, descending=True)
            cum = torch.cumsum(sq.double(), 0)
            curve = (cum[grid_t] / cum[-1]).numpy()   # empirical contraction delta(k) on the grid

            if a.policy in ("mvt", "mvtcap"):
                kstar[i], _ = en.mvt_optimum(curve, grid, d, radio)
                kstar_log.append(int(kstar[i]))
                if calibrating:
                    if bank[i] >= float(en.upload_energy(int(kstar[i]), d, radio)):
                        k = en.max_k_within(min(bank[i], battery[i]), d, radio)
                    if k < 1 or battery[i] < float(en.upload_energy(k, d, radio)):
                        continue                     # calibration only; nothing is sent or stored
                if a.policy == "mvtcap":
                    k = min(k, int(kstar[i]))        # send the per-joule optimum, keep the surplus

            # ---- top-k compress, upload, pay ----
            sent = torch.zeros(d)
            keep = order[:k]
            sent[keep] = p[keep]
            err[i] = p - sent
            agg += n_i[i] * sent
            wsum += n_i[i]

            cost = float(en.upload_energy(k, d, radio))
            battery[i] -= cost
            bank[i] -= float(en.upload_energy(k, d, radio_blind)) if a.policy == "blind" else cost
            energy_used[i] += cost
            bytes_sent[i] += float(en.payload_bytes(k, d))
            dk = float(cum[k - 1] / cum[-1])
            uploads[i] += 1
            sum_delta[i] += dk
            sum_k[i] += k
            last_upload[i] = r
            upload_rounds[i].append(r)
            n_part += 1
            rhos.append(k / d)
            deltas.append(dk)
            curve_r += curve
            curve_sum += curve
            curve_n += 1

        if wsum > 0:
            w = w + agg / wsum
        if n_part and r in (0, 9, 49, 99, R - 1):
            curve_by_round[r] = (curve_r / n_part).tolist()

        if r % a.eval_every == a.eval_every - 1 or r == 0 or r == R - 1:
            load_flat(model, w.clone())
            acc = evaluate(model, xte, yte)
        else:
            acc = None
        log["round"].append(r + 1)
        log["acc"].append(acc)
        log["energy"].append(float(energy_used.sum()))
        log["participants"].append(n_part)
        log["affordable"].append(int(sum(can_afford_next(i) for i in range(N))))
        log["mean_rho"].append(float(np.mean(rhos)) if rhos else None)
        log["mean_delta"].append(float(np.mean(deltas)) if deltas else None)
        if acc is not None and not a.quiet:
            print(f"[{a.tag}] r={r + 1:4d} acc={acc:.4f} part={n_part:2d} "
                  f"E={energy_used.sum() / 1e3:7.2f} kJ  {time.time() - t0:6.0f}s", flush=True)

    out = dict(
        args=vars(a), d=d, radio=dict(e_fixed=radio.e_fixed, e_tail=radio.e_tail, e_byte=radio.e_byte),
        soc=soc.tolist(), b0=[None if np.isinf(v) else float(v) for v in b0],
        n_samples=n_i.tolist(), log=log,
        client=dict(uploads=uploads.tolist(), sum_delta=sum_delta.tolist(), sum_k=sum_k.tolist(),
                    energy=energy_used.tolist(), bytes=bytes_sent.tolist(),
                    last_upload=last_upload.tolist(), upload_rounds=upload_rounds,
                    battery_left=[None if np.isinf(v) else float(v) for v in battery],
                    kstar=kstar.tolist()),
        grid=grid.tolist(), curve_mean=(curve_sum / max(curve_n, 1)).tolist(),
        curve_by_round=curve_by_round, kstar_log=kstar_log, seconds=time.time() - t0,
    )
    Path(a.out).parent.mkdir(parents=True, exist_ok=True)
    Path(a.out).write_text(json.dumps(out))
    print(f"[{a.tag}] done acc={log['acc'][-1]:.4f} E={energy_used.sum() / 1e3:.2f} kJ "
          f"in {time.time() - t0:.0f}s -> {a.out}", flush=True)


def parse():
    p = argparse.ArgumentParser()
    p.add_argument("--policy", required=True, choices=["fixed", "blind", "greedy", "mvt", "dos", "mvtcap"])
    p.add_argument("--rho", type=float, default=1.0, help="k/d for the fixed policy")
    p.add_argument("--seed", type=int, default=0)
    p.add_argument("--rounds", type=int, default=150)
    p.add_argument("--clients", type=int, default=30)
    p.add_argument("--alpha", type=float, default=0.5, help="Dirichlet concentration; <=0 for IID")
    p.add_argument("--local-steps", type=int, default=10)
    p.add_argument("--batch", type=int, default=32)
    p.add_argument("--lr", type=float, default=0.05)
    p.add_argument("--fl-fraction", type=float, default=0.10, help="share of present charge FL may spend")
    p.add_argument("--soc-min", type=float, default=0.05)
    p.add_argument("--unconstrained", action="store_true", help="infinite batteries")
    p.add_argument("--no-tail", action="store_true", help="charge the tail-free radio model")
    p.add_argument("--eval-every", type=int, default=5)
    p.add_argument("--threads", type=int, default=1)
    p.add_argument("--data", default="data/cifar10.npz")
    p.add_argument("--out", required=True)
    p.add_argument("--tag", default="")
    p.add_argument("--quiet", action="store_true")
    a = p.parse_args()
    a.tag = a.tag or f"{a.policy}{a.rho if a.policy == 'fixed' else ''}-s{a.seed}"
    return a


if __name__ == "__main__":
    run(parse())
