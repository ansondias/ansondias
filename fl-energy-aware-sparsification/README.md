# Energy-aware control of the sparsification ratio in federated learning with error feedback

End-term idea paper for *Next Generation Computing* (B.Tech AI & ML, Semester VII).

- **Paper:** [`paper/paper.pdf`](paper/paper.pdf) (IEEE conference format, 6 pages including references), source in [`paper/paper.tex`](paper/paper.tex)
- **Code:** `energy.py` (radio model and allocation rules), `fl_sim.py` (simulator), `analysis.py` (figures and tables), `paper_numbers.py` (every other number in the text)
- **Raw results:** one JSON log per run in `results/`, summary in `results/summary.json`

## The question

Top-k sparsification trades accuracy for uplink bytes. A battery-powered client is limited by joules, and
LTE radio energy is not linear in payload:

    E(s) = E_fixed + e_byte * s + P_tail * tau_tail

With the LTE measurements of Huang et al. (MobiSys 2012) the fixed part is 12.59 J per upload (12.27 J of it
is the tail) and a byte costs 5.33 µJ. For the 532,202-parameter CNN used here:

| k/d | payload | energy, bytes only | energy with tail |
|---|---|---|---|
| 0.01 | 34.6 kB | 0.18 J | 12.77 J |
| 0.1 | 279.4 kB | 1.49 J | 14.08 J |
| 1 | 2128.8 kB | 11.36 J | 23.94 J |

Top-1% removes 98% of the bytes and 47% of the energy. What sparsity should a client pick given its
remaining battery?

## What the paper does

1. Shows that the per-round program `max_k δ(k) s.t. E(s(k)) ≤ B_r` has no solution for any client whose
   per-round allowance is below the 12.59 J overhead, and extends it to a program over
   (participation rate q, sparsity k): `max q·δ(k) s.t. q·E(k) ≤ B̄`.
2. Solves it in closed form. The tail creates a battery-independent payload floor
   `k* = argmax_k δ(k)/E(k)`; the battery sets how often to upload. Without the tail the same program says
   to send as few coordinates as possible, as often as possible.
3. Tests it: FedAvg + top-k error feedback on CIFAR-10, 30 clients, Dirichlet(0.5) split, 150 rounds,
   heterogeneous batteries, 3 seeds, against fixed k/d ∈ {0.01, 0.1, 1} and three ablations.

## Results (mean ± sample std over 3 seeds)

| Policy | Acc. at 20 kJ | Acc. at 40 kJ | Final acc. | Fleet battery used | Clients surviving (of 30) |
|---|---|---|---|---|---|
| Fixed 1% | 49.3 ± 1.0 | 58.3 ± 0.3 | 59.5 ± 0.5 | 60.7% | 17.0 |
| Fixed 10% | 51.0 ± 0.9 | 59.5 ± 0.6 | 61.7 ± 0.7 | 64.8% | 16.0 |
| Fixed 100% | 46.2 ± 0.7 | 54.6 ± 0.7 | 60.5 ± 0.3 | 88.7% | 10.7 |
| EA tail-blind | 47.4 ± 1.9 | 54.4 ± 0.6 | 60.5 ± 0.3 | 88.8% | 10.7 |
| EA per-round | 50.4 ± 0.7 | 57.7 ± 0.8 | 62.5 ± 0.5 | 89.0% | 30.0 |
| EA dense-or-silent | 50.0 ± 0.9 | 57.5 ± 0.5 | 62.6 ± 0.2 | 88.7% | 30.0 |
| **EA-MVT (proposed)** | 50.5 ± 0.9 | 57.8 ± 0.8 | 62.5 ± 0.6 | 89.0% | 30.0 |
| EA-MVT-cap (added post hoc) | 52.6 ± 1.2 | 60.1 ± 1.0 | 62.7 ± 0.7 | 71.3% | 30.0 |

What held up:

- The measured payload floor is interior, k*/d ≈ 0.25. Top-k contraction is far from the worst case k/d:
  the top 1% of a local update carries 38% of its squared norm.
- With unlimited batteries, the energy-optimal fixed ratio moves from 1% (bytes-only accounting, 6.5× cheaper
  than 10%) to 10% (tail charged, 1% costs 9–13% more than 10%).
- Scheduling uploads against the battery keeps all 30 clients uploading to the last round and is worth about
  2 points over dense uploads at identical energy.
- An allocator that ignores the tail is no better than dense uploads.

What did not:

- The floor itself has no measurable effect on accuracy (EA-MVT vs per-round: −0.02 ± 0.11 points).
- The rule as derived is less accurate per joule than fixed 10% sparsity (−1.65 ± 0.92 points at 40 kJ). It
  turns surplus battery into contraction that buys no accuracy.
- By share of delivered contraction and Jain's index, the energy-aware rules are not fairer than sparse
  fixed baselines.

The capped variant (send exactly k*, keep the surplus) was added after seeing the table, so it is exploratory.
Run once on two fresh seeds (`results/confirm/`), it led fixed 10% by +0.4 and +1.2 points at 40 kJ and by
+0.4 and +1.2 points at round 150, with 30 surviving clients against 16 and 19. The sign of its lead agrees on
all five seeds; the size is about one point.

Everything here is simulation. Only uplink energy is charged, there is one radio model and one small CNN,
and differences under one point are at the edge of seed noise. See Section VII of the paper.

## Reproduce

Tested with Python 3.13.18, PyTorch 2.15.0 (CPU), NumPy 2.5.3, Matplotlib 3.11.2 on a 4-vCPU VM with
no GPU. One run takes 10–16 minutes on one thread; all 39 runs take about 8 CPU-hours (about 2 hours
with four in parallel). Seeds 0–2 are the main grid; seeds 3–4 are the fresh-seed confirmation.

```bash
pip install torch numpy matplotlib pillow
```

```bash
curl -O https://s3.amazonaws.com/fast-ai-imageclas/cifar10.tgz
mkdir -p data/raw && tar -xzf cifar10.tgz -C data/raw
python prepare_data.py data/raw/cifar10 data/cifar10.npz
```

```bash
DATA=data/cifar10.npz JOBS=4 ./run_all.sh        # 33 runs -> results/*.json
DATA=data/cifar10.npz JOBS=4 ./run_confirm.sh    # 6 runs  -> results/confirm/*.json
```

| Paper item | Command | Output |
|---|---|---|
| Fig. 1, Fig. 2, Fig. 3, Table II, Table III | `python analysis.py results figures` | `figures/fig_model.pdf`, `fig_accuracy.pdf`, `fig_fairness.pdf`; table values on stdout and in `results/summary.json` |
| Table I and every other number in the text | `python energy.py` and `python paper_numbers.py results` | stdout |
| Paper PDF | `cd paper && pdflatex paper.tex && pdflatex paper.tex` | `paper/paper.pdf` |

The run logs used in the paper are committed in `results/`, so the analysis commands work without
re-running training.

A single run: `python fl_sim.py --policy mvt --seed 0 --out results/mvt-s0.json`.
Policies: `fixed` (with `--rho`), `blind`, `greedy`, `dos`, `mvt`, `mvtcap`; add `--unconstrained` for
unlimited batteries.

## Submission files

`submission/` holds what the course asks for besides the code:

- `NGC_EndTerm_AnsonDias.pdf`: the paper
- `reproducibility_sheet.pdf` (source `.tex`): tool versions, seeds, hardware, runtime and the command for each figure
- `ai_use_declaration.md`: draft AI-use declaration, to be completed and signed by the student
- `references_to_verify.md`: every reference with a link, to be opened and checked by the student
