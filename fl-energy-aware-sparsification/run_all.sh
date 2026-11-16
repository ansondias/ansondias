#!/usr/bin/env bash
# Full experiment grid: 11 arms x 3 seeds, 150 rounds, 30 clients. Resumable.
# Usage: DATA=path/to/cifar10.npz JOBS=4 ./run_all.sh
set -u
DATA=${DATA:-data/cifar10.npz}; JOBS=${JOBS:-4}; OUT=${OUT:-results}; COMMON="--rounds 150 --clients 30 --lr 0.1 --quiet --data $DATA"
jobs_list() {
  for seed in 0 1 2; do
    for rho in 0.01 0.1 1.0; do echo "fixed$rho-s$seed --policy fixed --rho $rho --seed $seed"; done
    for p in blind greedy mvt dos mvtcap; do echo "$p-s$seed --policy $p --seed $seed"; done
  done
  for seed in 0 1 2; do   # infinite batteries: used to re-account energy with and without the tail
    for rho in 0.01 0.1 1.0; do echo "unc$rho-s$seed --policy fixed --rho $rho --seed $seed --unconstrained"; done
  done
}
jobs_list | while read -r name rest; do
  [ -s "$OUT/$name.json" ] || echo "$rest --tag $name --out $OUT/$name.json"
done | xargs -P "$JOBS" -I{} sh -c "python3 fl_sim.py $COMMON {}"
echo ALL_DONE
