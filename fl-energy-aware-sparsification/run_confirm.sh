#!/usr/bin/env bash
# Confirmation on seeds never used during development: the post hoc arm (mvtcap)
# against the best fixed baseline and the original rule.
set -u
DATA=${DATA:-data/cifar10.npz}; JOBS=${JOBS:-4}; OUT=${OUT:-results/confirm}; COMMON="--rounds 150 --clients 30 --lr 0.1 --quiet --data $DATA"
for seed in 3 4; do
  echo "fixed0.1-s$seed --policy fixed --rho 0.1 --seed $seed"
  echo "mvtcap-s$seed --policy mvtcap --seed $seed"
  echo "mvt-s$seed --policy mvt --seed $seed"
done | while read -r name rest; do
  [ -s "$OUT/$name.json" ] || echo "$rest --tag $name --out $OUT/$name.json"
done | xargs -P "$JOBS" -I{} sh -c "python3 fl_sim.py $COMMON {}"
echo ALL_DONE
