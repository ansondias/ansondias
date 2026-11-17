"""Print every number quoted in the paper text that analysis.py does not print.

analysis.py regenerates Figs. 1-3, Table II and Table III. This script reproduces the
remaining in-text numbers (Sections III-VI): per-upload energies, paired differences
between arms, tercile statistics, the fresh-seed confirmation and the model-size
extrapolation of the payload floor.

Usage:  python paper_numbers.py [results_dir]
"""
import glob
import json
import sys
from pathlib import Path

import numpy as np

import energy as en

RES = Path(sys.argv[1] if len(sys.argv) > 1 else "results")
D = 532_202


def load(name, seed, sub=""):
    return json.loads((RES / sub / f"{name}-s{seed}.json").read_text())


def evals(z):
    L = z["log"]
    m = [a is not None for a in L["acc"]]
    return np.array([a for a in L["acc"] if a is not None]) * 100, np.array(L["energy"])[m] / 1e3


def final3(z):
    return evals(z)[0][-3:].mean()


def at_energy(z, kj):
    acc, e = evals(z)
    return acc[e <= kj][-1]


def paired(a, b, f, seeds=(0, 1, 2), sub=""):
    d = np.array([f(load(a, s, sub)) - f(load(b, s, sub)) for s in seeds])
    return d


def ms(d):
    return f"{d.mean():+.2f} ± {d.std(ddof=1):.2f}" if len(d) > 1 else f"{d[0]:+.2f}"


print("== Section III: energy model ==")
tail, flat = en.lte_model(True), en.lte_model(False)
print(f"E_fixed={tail.e_fixed:.3f} J  P_tail*tau_tail={tail.e_tail:.3f} J  E0={tail.e0:.3f} J  e_byte={tail.e_byte * 1e6:.3f} uJ/B")
for rho in (0.01, 0.1, 1.0):
    k = int(rho * D)
    print(f"k/d={rho}: s={float(en.payload_bytes(k, D)) / 1e3:.1f} kB  E={float(en.upload_energy(k, D, tail)):.2f} J  "
          f"bytes-only={float(en.upload_energy(k, D, flat)):.2f} J")
b1, bd = float(en.payload_bytes(int(0.01 * D), D)), float(en.payload_bytes(D, D))
e1, ed = float(en.upload_energy(int(0.01 * D), D, tail)), float(en.upload_energy(D, D, tail))
print(f"top-1% vs dense: bytes removed {100 * (1 - b1 / bd):.1f}%, energy removed {100 * (1 - e1 / ed):.1f}%, "
      f"byte ratio {bd / b1:.1f}x, ceiling E(d)/E0 = {ed / tail.e0:.2f}")

print("\n== Section V: batteries ==")
for s in (0, 1, 2):
    z = load("mvt", s)
    B = np.array(z["b0"]) / z["args"]["rounds"]
    print(f"seed {s}: below E0 {int((B < tail.e0).sum())}, above E(d) {int((B >= ed).sum())}, fleet {sum(z['b0']) / 1e3:.1f} kJ")
print("mean fleet battery (kJ):", round(np.mean([sum(load('mvt', s)['b0']) for s in (0, 1, 2)]) / 1e3, 1))
t = [json.loads(Path(f).read_text())["seconds"] for f in glob.glob(str(RES / "*-s*.json")) + glob.glob(str(RES / "confirm" / "*-s*.json"))]
print(f"runs {len(t)}, per run {min(t) / 60:.1f}-{max(t) / 60:.1f} min, total {sum(t) / 3600:.1f} CPU-hours")

print("\n== Section VI-A: contraction and payload floor ==")
unc = [load("unc1.0", s) for s in (0, 1, 2)]
g = np.array(unc[0]["grid"]) / D
cm = np.mean([z["curve_mean"] for z in unc], axis=0)
d01 = np.interp(0.01, g, cm)
print(f"dense delta at 1%: {d01:.2f} ({d01 / 0.01:.0f}x the k/d bound), at 10%: {np.interp(0.1, g, cm):.2f}")
for rho in ("0.01", "0.1"):
    md = np.mean([np.mean([m for m in load(f"unc{rho}", s)["log"]["mean_delta"] if m]) for s in (0, 1, 2)])
    print(f"delivered delta under fixed {rho}: {md:.2f}")
ks = np.concatenate([np.array(load("mvt", s)["kstar_log"]) / D for s in (0, 1, 2)])
print(f"k*/d over EA-MVT uploads: median {np.median(ks):.2f}, p10 {np.percentile(ks, 10):.2f}, p90 {np.percentile(ks, 90):.2f}")
kk = np.maximum(1, np.round(g * D))
eta = cm / en.upload_energy(kk, D, tail)
j = int(np.argmax(eta))
print(f"eta at k*: {eta[j] / np.interp(0.01, g, eta):.1f}x eta(0.01d), {eta[j] / eta[-1]:.1f}x eta(d); k*/d of the mean curve {g[j]:.2f}")
for dd in (62_006, 11_173_962):
    kk2 = np.maximum(1, np.round(g * dd))
    e2 = cm / en.upload_energy(kk2, dd, tail)
    print(f"extrapolation d={dd}: k*/d={g[int(np.argmax(e2))]:.3f}")

print("\n== Section VI-C: paired differences (3 seeds) ==")
f40 = lambda z: at_energy(z, 40)
print("EA-MVT final", f"{np.mean([final3(load('mvt', s)) for s in (0, 1, 2)]):.1f}%",
      "fixed 10% final", f"{np.mean([final3(load('fixed0.1', s)) for s in (0, 1, 2)]):.1f}%")
for a, b, f, lab in [("mvt", "fixed1.0", final3, "final"), ("mvt", "fixed0.01", final3, "final"),
                     ("dos", "fixed1.0", final3, "final"), ("mvt", "greedy", final3, "final"),
                     ("mvt", "dos", final3, "final"), ("mvt", "fixed0.1", final3, "final"),
                     ("mvt", "fixed0.1", f40, "at 40 kJ"), ("mvtcap", "mvt", final3, "final"),
                     ("mvtcap", "fixed0.1", f40, "at 40 kJ"), ("mvtcap", "fixed0.1", final3, "final")]:
    print(f"{a} - {b} ({lab}): {ms(paired(a, b, f))}")
for k in ("mvt", "fixed0.1", "mvtcap"):
    fr = np.mean([sum(load(k, s)["client"]["energy"]) / sum(load(k, s)["b0"]) for s in (0, 1, 2)])
    print(f"{k}: fleet battery used {100 * fr:.0f}%")


def tier_stats(name):
    c, u = [], []
    for s in (0, 1, 2):
        z = load(name, s)
        o = np.argsort(z["soc"])[:10]
        c.append(np.array(z["client"]["sum_delta"])[o].mean())
        u.append(np.array(z["client"]["uploads"])[o].mean())
    return np.mean(c), np.mean(u)


cm_, um_ = tier_stats("mvt")
cg_, ug_ = tier_stats("greedy")
print(f"low tercile, EA-MVT vs per-round: contraction {100 * (cm_ / cg_ - 1):+.0f}%, uploads {100 * (um_ / ug_ - 1):+.0f}%")
z = [load("greedy", s) for s in (0, 1, 2)]
lr = np.mean([np.array(x["client"]["sum_k"])[np.argsort(x["soc"])[:10]].sum()
              / np.array(x["client"]["uploads"])[np.argsort(x["soc"])[:10]].sum() / D for x in z])
print(f"per-round low-tercile mean k/d: {lr:.2f}")
print(f"unlimited dense FedAvg final: {np.mean([final3(z) for z in unc]):.1f} ± {np.std([final3(z) for z in unc], ddof=1):.1f}")

print("\n== Section VI-E: fresh seeds 3 and 4 ==")
for a, b in (("mvtcap", "fixed0.1"), ("mvt", "fixed0.1")):
    print(f"{a} - {b}: at 40 kJ {np.round(paired(a, b, f40, (3, 4), 'confirm'), 1)}, "
          f"final {np.round(paired(a, b, final3, (3, 4), 'confirm'), 1)}")
for k in ("fixed0.1", "mvtcap"):
    sv = [int((np.array(load(k, s, "confirm")["client"]["last_upload"]) >= 135).sum()) for s in (3, 4)]
    print(f"{k} survivors on seeds 3, 4: {sv}")
