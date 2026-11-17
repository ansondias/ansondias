"""Turn results/*.json into the paper's tables (results/summary.json, stdout) and figures."""
import glob
import json
import sys
from pathlib import Path

import numpy as np
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt

import energy as en

RES = Path(sys.argv[1] if len(sys.argv) > 1 else "results")
FIG = Path(sys.argv[2] if len(sys.argv) > 2 else "figures")
FIG.mkdir(exist_ok=True)

ARMS = [("fixed0.01", "Fixed 1%"), ("fixed0.1", "Fixed 10%"), ("fixed1.0", "Fixed 100%"),
        ("blind", "EA tail-blind"), ("greedy", "EA per-round"), ("dos", "EA dense-or-silent"),
        ("mvt", "EA-MVT (proposed)"), ("mvtcap", "EA-MVT-cap (post hoc)")]
COL = {"fixed0.01": "#9ecae1", "fixed0.1": "#4292c6", "fixed1.0": "#08519c", "blind": "#fdae6b",
       "greedy": "#e6550d", "dos": "#74c476", "mvt": "#000000", "mvtcap": "#d62728"}
STY = {"fixed0.01": ":", "fixed0.1": "--", "fixed1.0": "-.", "blind": "--", "greedy": "-.",
       "dos": "--", "mvt": "-", "mvtcap": "-"}
BUDGETS_KJ = [20.0, 40.0, 60.0]
LAST = 15            # "surviving to round R" = uploads at least once in the last LAST rounds
TARGETS = [0.50, 0.55, 0.60]

plt.rcParams.update({"font.size": 8, "font.family": "DejaVu Serif", "mathtext.fontset": "dejavuserif",
                     "pdf.fonttype": 42, "axes.linewidth": 0.6,
                     "lines.linewidth": 1.2, "legend.fontsize": 6.5, "legend.frameon": False,
                     "axes.grid": True, "grid.linewidth": 0.3, "grid.alpha": 0.6,
                     "savefig.bbox": "tight", "savefig.pad_inches": 0.02})


def load(prefix):
    runs = [json.loads(Path(f).read_text()) for f in sorted(glob.glob(str(RES / f"{prefix}-s*.json")))]
    return runs


def evals(run):
    L = run["log"]
    m = [a is not None for a in L["acc"]]
    return (np.array(L["round"])[m], np.array([a for a in L["acc"] if a is not None]),
            np.array(L["energy"])[m] / 1e3)


def acc_at_energy(run, budget_kj):
    """Accuracy of the last evaluation taken before fleet energy exceeds the budget
    (the final accuracy if the arm never spends that much)."""
    _, acc, e = evals(run)
    ok = e <= budget_kj
    return acc[ok][-1] if ok.any() else np.nan


def jain(c):
    c = np.asarray(c, dtype=float)
    return c.sum() ** 2 / (len(c) * (c ** 2).sum())


def tiers(run):
    order = np.argsort(run["soc"])
    n = len(order) // 3
    return order[:n], order[n:2 * n], order[2 * n:]


def summarise(run):
    c, R = run["client"], run["args"]["rounds"]
    _, acc, _ = evals(run)
    low, mid, high = tiers(run)
    sd, up = np.array(c["sum_delta"]), np.array(c["uploads"])
    last = np.array(c["last_upload"])
    alive = last >= R - LAST
    s = dict(final=acc[-1], last3=acc[-3:].mean(), best=acc.max(),
             energy=run["log"]["energy"][-1] / 1e3,
             fleet=np.sum(run["b0"]) / 1e3 if run["b0"][0] is not None else np.nan,
             survive=alive.sum(), survive_low=alive[low].sum(),
             low_share=sd[low].sum() / sd.sum(), low_upload_share=up[low].sum() / up.sum(),
             jain=jain(sd), jain_uploads=jain(up), uploads=up.sum(),
             low_uploads=up[low].mean(), low_rho=np.sum(np.array(c["sum_k"])[low]) / max(up[low].sum(), 1) / run["d"],
             mean_rho=np.sum(c["sum_k"]) / up.sum() / run["d"],
             mbytes=np.sum(c["bytes"]) / 1e6)
    for b in BUDGETS_KJ:
        s[f"acc@{int(b)}"] = acc_at_energy(run, b)
    _, a2, e2 = evals(run)
    for t in TARGETS:
        hit = np.where(a2 >= t)[0]
        s[f"kJ@{int(t * 100)}"] = e2[hit[0]] if len(hit) else np.nan
    s["late_low_uploads"] = sum(sum(1 for x in c["upload_rounds"][i] if x >= R - LAST) for i in low)
    return s


def ms(vals, pct=True, nd=1):
    v = np.array(vals, dtype=float)
    f = 100.0 if pct else 1.0
    sd = np.nanstd(v, ddof=1) if np.sum(~np.isnan(v)) > 1 else 0.0   # sample std over seeds
    return f"{np.nanmean(v) * f:.{nd}f} $\\pm$ {sd * f:.{nd}f}"


summary = {}
print("=== Main table (mean +- std over seeds) ===")
for key, name in ARMS:
    runs = load(key)
    if not runs:
        continue
    S = [summarise(r) for r in runs]
    summary[key] = {k: [float(s[k]) for s in S] for k in S[0]}
    summary[key]["n_seeds"] = len(runs)
    row = (f"{name:20s} n={len(runs)} final {ms([s['final'] for s in S])} last3 {ms([s['last3'] for s in S])} | "
           + " ".join(f"@{int(b)}kJ {ms([s[f'acc@{int(b)}'] for s in S])}" for b in BUDGETS_KJ)
           + f" | E {ms([s['energy'] for s in S], False)} kJ of {np.mean([s['fleet'] for s in S]):.1f}"
           + f" | surv {np.mean([s['survive'] for s in S]):.1f} (low {np.mean([s['survive_low'] for s in S]):.1f})"
           + f" | lowshare {ms([s['low_share'] for s in S])} jain {ms([s['jain'] for s in S], False, 3)}"
           + f" | lowupl {np.mean([s['low_uploads'] for s in S]):.1f} lowrho {np.mean([s['low_rho'] for s in S]):.3f}"
           + f" meanrho {np.mean([s['mean_rho'] for s in S]):.3f} MB {np.mean([s['mbytes'] for s in S]):.0f}"
           + " | " + " ".join(f"kJ@{int(t * 100)} {np.mean([s[f'kJ@{int(t * 100)}'] for s in S]):.1f}" for t in TARGETS)
           + f" | lateLow {np.mean([s['late_low_uploads'] for s in S]):.1f} uploads {np.mean([s['uploads'] for s in S]):.0f}")
    print(row)

# ---- Energy to accuracy from the infinite-battery runs, with and without the tail ----
print("\n=== Energy-to-accuracy, infinite batteries (per-client mean, J) ===")
d = None
eta = {}
for rho in ("0.01", "0.1", "1.0"):
    runs = load(f"unc{rho}")
    if not runs:
        continue
    d = runs[0]["d"]
    N = runs[0]["args"]["clients"]
    k = max(1, int(round(float(rho) * d)))
    e_tail = float(en.upload_energy(k, d, en.lte_model(True)))
    e_flat = float(en.upload_energy(k, d, en.lte_model(False)))
    finals = [evals(r)[1][-1] for r in runs]
    eta[rho] = dict(final=[float(x) for x in finals], e_tail=e_tail, e_flat=e_flat,
                    mbytes=float(en.payload_bytes(k, d)) / 1e6, targets={})
    line = f"rho={rho:5s} final {ms(finals)}  E/upload tail {e_tail:.2f} J flat {e_flat:.3f} J |"
    for t in TARGETS:
        rr = []
        for r in runs:
            rounds, acc, _ = evals(r)
            hit = np.where(acc >= t)[0]
            rr.append(rounds[hit[0]] if len(hit) else np.nan)
        eta[rho]["targets"][str(t)] = [float(x) for x in rr]
        m = np.nanmean(rr) if not np.all(np.isnan(rr)) else np.nan
        line += f" {int(t * 100)}%: R={m:.0f} tail={m * e_tail / 1e3:.2f}kJ flat={m * e_flat / 1e3:.3f}kJ |"
    print(line)
summary["unconstrained"] = eta

# ---- contraction curve statistics ----
mv = load("mvt")
if mv:
    ks = np.concatenate([np.array(r["kstar_log"]) / r["d"] for r in mv])
    summary["kstar"] = dict(mean=float(ks.mean()), median=float(np.median(ks)),
                            p10=float(np.percentile(ks, 10)), p90=float(np.percentile(ks, 90)))
    print("\nk*/d over all MVT client-rounds:", summary["kstar"])
dense = load("unc1.0") or load("fixed1.0")
ef = load("unc0.01") or load("fixed0.01")
curves = {}
for tag, runs in (("dense", dense), ("ef1", ef)):
    if runs:
        g = np.array(runs[0]["grid"]) / runs[0]["d"]
        cm = np.mean([r["curve_mean"] for r in runs], axis=0)
        curves[tag] = (g, cm)
        pts = {p: float(np.interp(p, g, cm)) for p in (0.001, 0.01, 0.05, 0.1, 0.2, 0.3, 0.5)}
        summary[f"curve_{tag}"] = pts
        print(f"mean empirical delta(k) [{tag}]:", {k: round(v, 3) for k, v in pts.items()})
(RES / "summary.json").write_text(json.dumps(summary, indent=1))

# ================= Figure 1: energy model, contraction, contraction per joule =================
if curves:
    d = d or (dense or ef)[0]["d"]
    tail, flat = en.lte_model(True), en.lte_model(False)
    g, cm = curves.get("dense", next(iter(curves.values())))
    kk = np.maximum(1, np.round(g * d))
    fig, ax = plt.subplots(1, 3, figsize=(7.16, 1.95))
    ax[0].loglog(g, en.upload_energy(kk, d, tail), "k-", label="LTE with tail")
    ax[0].loglog(g, en.upload_energy(kk, d, flat), "--", color="#e6550d", label=r"bytes only ($E_0{=}0$)")
    ax[0].set_xlabel("fraction sent, $k/d$ (–)"); ax[0].set_ylabel("energy per upload (J)"); ax[0].legend(loc="lower right")
    ax[0].set_title("(a) uplink energy", fontsize=8)
    ax[1].semilogx(g, cm, "k-", label="dense update")
    if "ef1" in curves:
        ax[1].semilogx(*curves["ef1"], "-", color="#4292c6", label="EF memory (1%)")
    ax[1].semilogx(g, g, ":", color="gray", label="worst case $k/d$")
    ax[1].set_xlabel("fraction sent, $k/d$ (–)"); ax[1].set_ylabel(r"contraction $\delta(k)$ (–)"); ax[1].legend(loc="upper left", handlelength=1.6)
    ax[1].set_title("(b) top-$k$ contraction", fontsize=8)
    for model, c, ls, lab in ((tail, "k", "-", "tail"), (flat, "#e6550d", "--", "bytes only")):
        eff = cm / en.upload_energy(kk, d, model)
        ax[2].semilogx(g, eff / eff.max(), ls, color=c, label=lab)
        j = int(np.argmax(eff))
        ax[2].plot(g[j], 1.0, "o", color=c, ms=4)
    effw = g / en.upload_energy(kk, d, tail)
    ax[2].semilogx(g, effw / effw.max(), ":", color="gray", label="tail, $k/d$ bound")
    ax[2].set_xlabel("fraction sent, $k/d$ (–)"); ax[2].set_ylabel(r"$\eta(k)/\max\,\eta$ (–)"); ax[2].legend(loc="center left", bbox_to_anchor=(0.0, 0.66), handlelength=1.6)
    ax[2].set_title("(c) contraction per joule", fontsize=8)
    for a in ax:
        a.set_xlim(1e-4, 1)
    fig.tight_layout(w_pad=0.6)
    fig.savefig(FIG / "fig_model.pdf"); plt.close(fig)

# ================= Figure 2: accuracy vs round and vs fleet energy =================
fig, ax = plt.subplots(1, 2, figsize=(7.16, 2.25))
for key, name in ARMS:
    runs = load(key)
    if not runs:
        continue
    rr = evals(runs[0])[0]
    acc = np.mean([evals(r)[1] for r in runs], axis=0) * 100
    e = np.mean([evals(r)[2] for r in runs], axis=0)
    kw = dict(color=COL[key], ls=STY[key], label=name, lw=1.6 if key == "mvt" else 1.1)
    ax[0].plot(rr, acc, **kw)
    ax[1].plot(e, acc, **kw)
ax[0].set_xlabel("communication round (count)"); ax[0].set_ylabel("test accuracy (%)"); ax[0].set_title("(a) accuracy vs. round", fontsize=8)
ax[1].set_xlabel("fleet uplink energy spent (kJ)"); ax[1].set_ylabel("test accuracy (%)"); ax[1].set_title("(b) accuracy vs. energy", fontsize=8)
ax[0].set_ylim(30, None); ax[1].set_ylim(30, None)
h, l = ax[0].get_legend_handles_labels()
fig.tight_layout(w_pad=0.8, rect=(0, 0.15, 1, 1))
fig.legend(h, l, loc="lower center", ncol=4, columnspacing=1.2, handlelength=2.4)
fig.savefig(FIG / "fig_accuracy.pdf"); plt.close(fig)

# ================= Figure 3: participation and per-tier contribution =================
fig, ax = plt.subplots(1, 2, figsize=(7.16, 2.1))
w = 0.1
for j, (key, name) in enumerate(ARMS):
    runs = load(key)
    if not runs:
        continue
    part = np.mean([r["log"]["participants"] for r in runs], axis=0)
    sm = np.convolve(part, np.ones(10) / 10, mode="valid")
    ax[0].plot(np.arange(10, len(part) + 1), sm, color=COL[key], ls=STY[key], label=name,
               lw=1.6 if key == "mvt" else 1.1)
    tot = np.zeros(3)
    for r in runs:
        sd = np.array(r["client"]["sum_delta"])
        tot += np.array([sd[t].mean() for t in tiers(r)])
    ax[1].bar(np.arange(3) + (j - 3.5) * w, tot / len(runs), w, color=COL[key], label=name,
              edgecolor="k", linewidth=0.3)
ax[0].set_xlabel("communication round (count)"); ax[0].set_ylabel("uploading clients (count)")
ax[0].set_title("(a) clients uploading per round (10-round mean)", fontsize=8)
ax[1].set_xticks(range(3)); ax[1].set_xticklabels(["low battery", "mid battery", "high battery"])
ax[1].set_xlabel("battery tercile (10 clients each)"); ax[1].set_ylabel(r"$c_i=\sum_r \delta_i^r$ per client (–)")
ax[1].set_title("(b) delivered contraction per client, by battery tercile", fontsize=8)
h, l = ax[1].get_legend_handles_labels()
fig.tight_layout(w_pad=0.8, rect=(0, 0.17, 1, 1))
fig.legend(h, l, loc="lower center", ncol=4, columnspacing=1.2)
fig.savefig(FIG / "fig_fairness.pdf"); plt.close(fig)
print("\nfigures ->", FIG)
