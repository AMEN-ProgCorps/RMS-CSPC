#!/usr/bin/env python3
"""DCS OCR worker — PaddleOCR → JSON for Laravel DrrOcrService / RegisterScanService.

Usage:
  python paddle_ocr.py /path/to/page.jpg

Stdout JSON:
  { ok, text, words[{t,x,y,w,h,conf}], lines[{t,x,y,w,h}], image_w, image_h }
Boxes are normalized 0–1 relative to image size. conf is 0–100.

Only JSON is written to stdout. Paddle download/log noise goes to stderr.
"""

from __future__ import annotations

import contextlib
import json
import os
import sys
from pathlib import Path


def fail(message: str, code: int = 1) -> None:
    print(json.dumps({"ok": False, "error": message, "text": "", "words": [], "lines": []}))
    sys.exit(code)


@contextlib.contextmanager
def stdout_to_stderr():
    """Keep stdout reserved for the final JSON payload."""
    old = sys.stdout
    sys.stdout = sys.stderr
    try:
        yield
    finally:
        sys.stdout = old


def ensure_model_home() -> None:
    """Use shared model cache (Docker: /opt/paddleocr) so appuser does not re-download."""
    model_home = os.environ.get("PADDLEOCR_HOME") or os.environ.get("PADDLE_HOME")
    if model_home:
        Path(model_home).mkdir(parents=True, exist_ok=True)
        os.environ["HOME"] = model_home


def load_image_size(path: str) -> tuple[int, int]:
    try:
        from PIL import Image

        with Image.open(path) as img:
            return int(img.size[0]), int(img.size[1])
    except Exception:
        return 1, 1


def box_to_norm(box, img_w: int, img_h: int) -> dict[str, float]:
    xs = [float(p[0]) for p in box]
    ys = [float(p[1]) for p in box]
    left = min(xs)
    top = min(ys)
    right = max(xs)
    bottom = max(ys)
    w = max(right - left, 1.0)
    h = max(bottom - top, 1.0)
    return {
        "x": left / img_w,
        "y": top / img_h,
        "w": w / img_w,
        "h": h / img_h,
    }


def run_paddle(image_path: str):
    try:
        from paddleocr import PaddleOCR
    except ImportError as exc:
        fail(f"paddleocr not installed: {exc}")

    # CPU English model; angle classifier helps rotated scans.
    with stdout_to_stderr():
        ocr = PaddleOCR(
            use_angle_cls=True,
            lang="en",
            show_log=False,
            use_gpu=False,
        )

        if hasattr(ocr, "ocr"):
            result = ocr.ocr(image_path, cls=True)
            page = result[0] if result else None
            if not page:
                return []
            rows = []
            for item in page:
                if not item or len(item) < 2:
                    continue
                box, meta = item[0], item[1]
                text = (meta[0] if isinstance(meta, (list, tuple)) else str(meta)).strip()
                conf = float(meta[1]) if isinstance(meta, (list, tuple)) and len(meta) > 1 else 1.0
                if text:
                    rows.append((box, text, conf))
            return rows

        # PaddleOCR 3.x fallback
        if hasattr(ocr, "predict"):
            result = ocr.predict(image_path)
            rows = []
            for page in result or []:
                texts = page.get("rec_texts") or page.get("texts") or []
                scores = page.get("rec_scores") or page.get("scores") or []
                polys = page.get("dt_polys") or page.get("rec_polys") or page.get("boxes") or []
                for i, text in enumerate(texts):
                    text = str(text or "").strip()
                    if not text:
                        continue
                    box = polys[i] if i < len(polys) else [[0, 0], [1, 0], [1, 1], [0, 1]]
                    conf = float(scores[i]) if i < len(scores) else 1.0
                    rows.append((box, text, conf))
            return rows

    fail("Unsupported PaddleOCR API (expected .ocr or .predict)")


def main() -> None:
    ensure_model_home()

    if len(sys.argv) < 2:
        fail("image path required")

    image_path = sys.argv[1]
    if not Path(image_path).is_file():
        fail(f"image not found: {image_path}")

    img_w, img_h = load_image_size(image_path)
    img_w = max(img_w, 1)
    img_h = max(img_h, 1)

    try:
        rows = run_paddle(image_path)
    except Exception as exc:  # noqa: BLE001 — surface to PHP
        fail(str(exc))

    words = []
    lines = []
    for box, text, conf01 in rows:
        conf = max(0.0, min(100.0, float(conf01) * 100.0))
        if conf < 20:
            continue
        geom = box_to_norm(box, img_w, img_h)
        # Skip huge low-info blobs (logos / stamps).
        if geom["w"] > 0.48 and len(text) < 22:
            continue
        if geom["h"] > 0.09 and len(text) < 14:
            continue

        # Paddle line boxes ≈ word/phrase boxes; split into word tokens for highlighting.
        parts = [p for p in text.split() if p]
        if len(parts) <= 1:
            words.append({"t": text, **geom, "conf": conf})
            lines.append({"t": text, **geom})
            continue

        slice_w = geom["w"] / len(parts)
        for i, part in enumerate(parts):
            words.append(
                {
                    "t": part,
                    "x": geom["x"] + slice_w * i,
                    "y": geom["y"],
                    "w": max(slice_w * 0.92, 0.002),
                    "h": geom["h"],
                    "conf": conf,
                }
            )
        lines.append({"t": text, **geom})

    # Newlines keep DRF label/value parsing reliable (RegisterScanService).
    lines_sorted = sorted(lines, key=lambda row: (round(row["y"], 3), round(row["x"], 3)))
    text = "\n".join(line["t"] for line in lines_sorted).strip()
    if not text:
        text = " ".join(w["t"] for w in words).strip()

    print(
        json.dumps(
            {
                "ok": bool(text),
                "text": text,
                "words": words,
                "lines": lines_sorted,
                "image_w": img_w,
                "image_h": img_h,
                "engine": "paddleocr",
            }
        )
    )


if __name__ == "__main__":
    main()
