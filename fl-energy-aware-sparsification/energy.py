"""Uplink radio energy model and sparsity allocation rules.

Energy model
------------
    E(s) = E_fixed + e_byte * s + P_tail * tau_tail      [joules, s in bytes]

All constants are the LTE measurements of Huang et al., "A Close Examination of
Performance and Power Characteristics of 4G LTE Networks", MobiSys 2012:

    promotion (RRC_IDLE -> RRC_CONNECTED):  1210.7 mW for 260.1 ms
    tail (RRC_CONNECTED after last packet): 1060.0 mW for 11576.0 ms
    data transfer power:  P = alpha_u * t_u + alpha_d * t_d + beta
        alpha_u = 438.39 mW/Mbps, alpha_d = 51.97 mW/Mbps, beta = 1288.04 mW
    median LTE uplink throughput: 5.64 Mbps

For an upload-only transfer of s bytes at throughput t_u the transfer lasts
8 s / (t_u * 1e6) seconds at power alpha_u * t_u + beta, so the energy is linear
in s with slope   e_byte = 8 * (alpha_u + beta / t_u) * 1e-9  J/byte.
"""
from dataclasses import dataclass
import math

import numpy as np

# --- Huang et al. (MobiSys 2012) LTE constants ------------------------------
P_PROMO_MW = 1210.7
T_PROMO_MS = 260.1
P_TAIL_MW = 1060.0
T_TAIL_MS = 11576.0
ALPHA_U_MW_PER_MBPS = 438.39
BETA_MW = 1288.04
UPLINK_MBPS = 5.64

VALUE_BYTES = 4.0  # float32 per transmitted coordinate


@dataclass(frozen=True)
class RadioModel:
    """E(s) = e_fixed + e_byte * s + e_tail, in joules."""

    e_fixed: float
    e_tail: float
    e_byte: float

    @property
    def e0(self) -> float:
        """Payload-independent overhead per upload (promotion + tail)."""
        return self.e_fixed + self.e_tail

    def energy(self, nbytes):
        return self.e0 + self.e_byte * np.asarray(nbytes, dtype=np.float64)


def lte_model(tail: bool = True, uplink_mbps: float = UPLINK_MBPS) -> RadioModel:
    """LTE model. tail=False drops promotion and tail, leaving E = e_byte * s,
    the bytes-proportional model that communication-efficiency work implicitly uses."""
    e_byte = 8.0 * (ALPHA_U_MW_PER_MBPS + BETA_MW / uplink_mbps) * 1e-9
    if not tail:
        return RadioModel(0.0, 0.0, e_byte)
    return RadioModel(
        e_fixed=P_PROMO_MW * T_PROMO_MS * 1e-6,
        e_tail=P_TAIL_MW * T_TAIL_MS * 1e-6,
        e_byte=e_byte,
    )


def index_bytes(d: int) -> float:
    """Bytes needed to address one of d coordinates."""
    return math.ceil(math.log2(d)) / 8.0


def payload_bytes(k, d: int):
    """Uplink payload of a top-k update: cheapest of
    (value, index) list, values + d-bit occupancy bitmap, or the dense vector."""
    k = np.asarray(k, dtype=np.float64)
    coo = (VALUE_BYTES + index_bytes(d)) * k
    bitmap = VALUE_BYTES * k + d / 8.0
    dense = VALUE_BYTES * d
    return np.minimum(np.minimum(coo, bitmap), dense)


def upload_energy(k, d: int, radio: RadioModel):
    return radio.energy(payload_bytes(k, d))


def max_k_within(budget: float, d: int, radio: RadioModel) -> int:
    """Per-round program:  max_k delta(k)  s.t.  E(s(k)) <= budget.

    delta is nondecreasing in k and E(s(k)) is nondecreasing in k, so the
    solution is the largest feasible k. Returns 0 when even k = 1 is infeasible.
    """
    if budget >= radio.e0 + radio.e_byte * VALUE_BYTES * d:
        return d
    nbytes = (budget - radio.e0) / radio.e_byte
    if nbytes <= 0:
        return 0
    k_coo = nbytes / (VALUE_BYTES + index_bytes(d))
    k_bitmap = (nbytes - d / 8.0) / VALUE_BYTES
    k = int(math.floor(max(k_coo, k_bitmap) + 1e-9))
    return max(0, min(k, d))


def k_grid(d: int, n: int = 400) -> np.ndarray:
    """Log-spaced candidate k values in [1, d], always containing d."""
    g = np.unique(np.round(np.logspace(0, math.log10(d), n)).astype(np.int64))
    return g


def mvt_optimum(delta_of_k: np.ndarray, grid: np.ndarray, d: int, radio: RadioModel):
    """Payload that maximises contraction per joule:  k* = argmax_k delta(k) / E(s(k)).

    Returns (k*, efficiency curve). With e0 = 0 and a concave delta the argmax
    sits at the smallest k; with e0 > 0 it is interior or dense.
    """
    eff = delta_of_k / upload_energy(grid, d, radio)
    return int(grid[int(np.argmax(eff))]), eff


if __name__ == "__main__":
    d = 532_202
    for tail in (True, False):
        r = lte_model(tail)
        print(f"tail={tail}: E_fixed={r.e_fixed:.4f} J  E_tail={r.e_tail:.4f} J  "
              f"E0={r.e0:.4f} J  e_byte={r.e_byte * 1e6:.4f} uJ/byte")
        for rho in (0.001, 0.01, 0.1, 1.0):
            k = int(rho * d)
            print(f"   k/d={rho:<6} bytes={float(payload_bytes(k, d)):>12.0f}  "
                  f"E={float(upload_energy(k, d, r)):8.3f} J")
