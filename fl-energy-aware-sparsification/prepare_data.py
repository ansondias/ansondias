"""Convert the CIFAR-10 PNG tree (train/<class>/*.png, test/<class>/*.png) into one .npz.

The PNG tree is the lossless fast.ai mirror of CIFAR-10:
    https://s3.amazonaws.com/fast-ai-imageclas/cifar10.tgz
Usage:  python prepare_data.py <extracted cifar10 dir> <out.npz>
"""
import sys
from pathlib import Path

import numpy as np
from PIL import Image

CLASSES = ["airplane", "automobile", "bird", "cat", "deer",
           "dog", "frog", "horse", "ship", "truck"]


def load_split(root: Path):
    xs, ys = [], []
    for label, name in enumerate(CLASSES):
        files = sorted((root / name).glob("*.png"))
        for f in files:
            xs.append(np.asarray(Image.open(f).convert("RGB"), dtype=np.uint8))
        ys += [label] * len(files)
    return np.stack(xs), np.asarray(ys, dtype=np.int64)


if __name__ == "__main__":
    src, out = Path(sys.argv[1]), Path(sys.argv[2])
    xtr, ytr = load_split(src / "train")
    xte, yte = load_split(src / "test")
    assert xtr.shape == (50000, 32, 32, 3) and xte.shape == (10000, 32, 32, 3)
    assert all((ytr == c).sum() == 5000 for c in range(10))
    assert all((yte == c).sum() == 1000 for c in range(10))
    np.savez(out, x_train=xtr, y_train=ytr, x_test=xte, y_test=yte)
    print("wrote", out, xtr.shape, xte.shape)
