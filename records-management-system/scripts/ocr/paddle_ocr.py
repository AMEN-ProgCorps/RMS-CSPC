#!/usr/bin/env python3
"""DCS OCR worker — PaddleOCR → JSON for Laravel DrrOcrService / RegisterScanService.

Usage:
  python paddle_ocr.py /path/to/page.jpg

Stdout JSON:
  { ok, text, words[], lines[], blocks[], tables[], signatures[], footers[], figures[], image_w, image_h }
Boxes are normalized 0–1 relative to image size. conf is 0–100.
Layout fields are inferred heuristically from OCR lines (paragraphs, tables,
signature slots, footers, flow regions) for accurate DRR highlighting.

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
    """Use a writable model cache so PHP-FPM (appuser) does not fail on /opt."""
    candidates = []
    env_home = os.environ.get("PADDLEOCR_HOME") or os.environ.get("PADDLE_HOME")
    if env_home:
        candidates.append(env_home)
    candidates.extend(["/opt/paddleocr", "/tmp/paddleocr"])

    for model_home in candidates:
        try:
            path = Path(model_home)
            path.mkdir(parents=True, exist_ok=True)
            probe = path / ".write_test"
            probe.write_text("ok", encoding="utf-8")
            probe.unlink(missing_ok=True)
            os.environ["HOME"] = model_home
            os.environ["PADDLEOCR_HOME"] = model_home
            return
        except OSError:
            continue


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


def visual_text_width(text: str) -> float:
    """Approximate glyph width so phrase boxes split at the printed words."""
    width = 0.0
    for char in text:
        if char in "ilI1|!.,:;'`":
            width += 0.48
        elif char in "mwMW@%&":
            width += 1.35
        elif char.isupper() or char.isdigit():
            width += 1.08
        else:
            width += 0.95
    return max(width, 0.5)


def split_phrase_geometry(geom: dict[str, float], parts: list[str]) -> list[dict[str, float]]:
    """Split one Paddle phrase box into word boxes, retaining space gaps."""
    if not parts:
        return []

    weights = [visual_text_width(part) for part in parts]
    gap = 0.30
    total = sum(weights) + gap * max(len(parts) - 1, 0)
    if total <= 0:
        return []

    unit = geom["w"] / total
    cursor = geom["x"]
    boxes = []
    for index, weight in enumerate(weights):
        word_w = max(unit * weight, 0.002)
        boxes.append(
            {
                "x": cursor,
                "y": geom["y"],
                "w": max(word_w * 0.98, 0.002),
                "h": geom["h"],
            }
        )
        cursor += word_w + (unit * gap if index < len(parts) - 1 else 0.0)
    return boxes


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

        word_boxes = split_phrase_geometry(geom, parts)
        for part, word_geom in zip(parts, word_boxes):
            words.append(
                {
                    "t": part,
                    **word_geom,
                    "conf": conf,
                }
            )
        lines.append({"t": text, **geom})

    # Newlines keep DRF label/value parsing reliable (RegisterScanService).
    lines_sorted = sorted(lines, key=lambda row: (round(row["y"], 3), round(row["x"], 3)))
    text = "\n".join(line["t"] for line in lines_sorted).strip()
    if not text:
        text = " ".join(w["t"] for w in words).strip()

    structure = infer_layout_structure(lines_sorted, words)

    print(
        json.dumps(
            {
                "ok": bool(text),
                "text": text,
                "words": words,
                "lines": lines_sorted,
                "blocks": structure["blocks"],
                "tables": structure["tables"],
                "signatures": structure["signatures"],
                "footers": structure["footers"],
                "figures": structure["figures"],
                "image_w": img_w,
                "image_h": img_h,
                "engine": "paddleocr+layout",
            }
        )
    )


def _line_box(line: dict) -> dict[str, float]:
    return {
        "x": float(line.get("x", 0)),
        "y": float(line.get("y", 0)),
        "w": float(line.get("w", 0)),
        "h": float(line.get("h", 0)),
    }


def _union_box(boxes: list[dict[str, float]]) -> dict[str, float]:
    if not boxes:
        return {"x": 0.0, "y": 0.0, "w": 0.0, "h": 0.0}
    min_x = min(b["x"] for b in boxes)
    min_y = min(b["y"] for b in boxes)
    max_x = max(b["x"] + b["w"] for b in boxes)
    max_y = max(b["y"] + b["h"] for b in boxes)
    return {
        "x": min_x,
        "y": min_y,
        "w": max(max_x - min_x, 0.01),
        "h": max(max_y - min_y, 0.008),
    }


def _words_in_box(words: list[dict], box: dict[str, float], pad: float = 0.008) -> list[dict]:
    out = []
    for w in words:
        cx = float(w.get("x", 0)) + float(w.get("w", 0)) / 2
        cy = float(w.get("y", 0)) + float(w.get("h", 0)) / 2
        if (
            box["x"] - pad <= cx <= box["x"] + box["w"] + pad
            and box["y"] - pad <= cy <= box["y"] + box["h"] + pad
        ):
            out.append(w)
    return out


def infer_layout_structure(lines: list[dict], words: list[dict]) -> dict:
    """Heuristic layout from OCR lines — paragraphs, tables, signatures, footers, figures.

    Works without PP-Structure models so Docker deploys stay reliable; structure
    fields stay empty-safe for older clients.
    """
    import re

    blocks: list[dict] = []
    tables: list[dict] = []
    signatures: list[dict] = []
    footers: list[dict] = []
    figures: list[dict] = []

    if not lines:
        return {
            "blocks": blocks,
            "tables": tables,
            "signatures": signatures,
            "footers": footers,
            "figures": figures,
        }

    sig_re = re.compile(r"^(prepared|reviewed|approved)\s*by\b", re.I)
    footer_re = re.compile(
        r"effectivity|^\s*rev\.?\s*\d|^\s*page\s*[:.]?\s*\d|\bpage\s*:\s*\d+\s*of\s*\d+",
        re.I,
    )
    flow_re = re.compile(
        r"process\s*flow|procedure|flowchart|start\b|preparation\b|submission\b",
        re.I,
    )
    section_re = re.compile(r"^\s*\d+(\.\d+)*\.?\s+\S+")

    # --- Footer band (bottom 12%) ---
    footer_lines = [ln for ln in lines if float(ln.get("y", 0)) >= 0.88 or footer_re.search(str(ln.get("t", "")))]
    footer_fields: dict[str, dict] = {}
    for ln in footer_lines:
        t = str(ln.get("t", "")).strip()
        box = _line_box(ln)
        key = "footer"
        if re.search(r"effectivity", t, re.I):
            key = "effectivity"
        elif re.search(r"^\s*rev\.?\b|\brev\.?\s*\d", t, re.I):
            key = "rev"
        elif re.search(r"page\s*[:.]?\s*\d", t, re.I):
            key = "page"
        footer_fields[key] = {
            "key": key,
            "text": t,
            "box": box,
            "words": _words_in_box(words, box),
        }
    if footer_fields:
        fbox = _union_box([f["box"] for f in footer_fields.values()])
        footers.append(
            {
                "type": "footer",
                "box": fbox,
                "fields": list(footer_fields.values()),
                "text": " | ".join(f["text"] for f in footer_fields.values()),
            }
        )

    footer_ys = {id(ln) for ln in footer_lines}

    # --- Signature labels ---
    body_lines = [ln for ln in lines if id(ln) not in footer_ys]
    for i, ln in enumerate(body_lines):
        t = str(ln.get("t", "")).strip()
        m = sig_re.match(t)
        if not m:
            continue
        role_key = m.group(1).lower()
        label_box = _line_box(ln)
        # Collect nearby lines below/right for name + role (same column band).
        name_line = None
        role_line = None
        for j in range(i + 1, min(i + 6, len(body_lines))):
            cand = body_lines[j]
            cb = _line_box(cand)
            # Same horizontal band (signature column)
            if abs(cb["x"] - label_box["x"]) > 0.28 and cb["x"] + cb["w"] < label_box["x"]:
                continue
            if cb["y"] < label_box["y"] - 0.01:
                continue
            if cb["y"] > label_box["y"] + 0.22:
                break
            ct = str(cand.get("t", "")).strip()
            if sig_re.match(ct):
                break
            if name_line is None and len(ct) >= 3 and not footer_re.search(ct):
                name_line = cand
                continue
            if name_line is not None and role_line is None:
                role_line = cand
                break

        fields = [
            {
                "key": "label",
                "text": t,
                "box": label_box,
                "words": _words_in_box(words, label_box),
            }
        ]
        if name_line is not None:
            nb = _line_box(name_line)
            fields.append(
                {
                    "key": "name",
                    "text": str(name_line.get("t", "")).strip(),
                    "box": nb,
                    "words": _words_in_box(words, nb),
                }
            )
        if role_line is not None:
            rb = _line_box(role_line)
            fields.append(
                {
                    "key": "role",
                    "text": str(role_line.get("t", "")).strip(),
                    "box": rb,
                    "words": _words_in_box(words, rb),
                }
            )
        signatures.append(
            {
                "type": "signature",
                "role": role_key,
                "box": _union_box([f["box"] for f in fields]),
                "fields": fields,
                "text": " / ".join(f["text"] for f in fields),
                "x": label_box["x"],
            }
        )

    # Sort signature slots left-to-right within same role for multi-column reviewers.
    signatures.sort(key=lambda s: (s.get("role", ""), float(s.get("x", 0))))

    sig_line_ids = set()
    for sig in signatures:
        for f in sig.get("fields", []):
            for w in f.get("words", []):
                sig_line_ids.add(id(w))

    # --- Paragraph / title blocks (cluster by vertical gaps) ---
    para_lines = []
    for ln in body_lines:
        t = str(ln.get("t", "")).strip()
        if not t:
            continue
        if sig_re.match(t):
            continue
        # Skip lines already claimed as signature name/role (approx by y overlap with sig boxes)
        lb = _line_box(ln)
        in_sig = False
        for sig in signatures:
            sb = sig["box"]
            cy = lb["y"] + lb["h"] / 2
            if sb["y"] - 0.01 <= cy <= sb["y"] + sb["h"] + 0.02:
                if lb["x"] + lb["w"] * 0.5 >= sb["x"] - 0.02 and lb["x"] <= sb["x"] + sb["w"] + 0.02:
                    in_sig = True
                    break
        if in_sig:
            continue
        para_lines.append(ln)

    current: list[dict] = []
    prev_y = None

    def flush_para():
        nonlocal current
        if not current:
            return
        boxes = [_line_box(ln) for ln in current]
        box = _union_box(boxes)
        text_join = " ".join(str(ln.get("t", "")).strip() for ln in current)
        first = str(current[0].get("t", "")).strip()
        btype = "title" if section_re.match(first) or (len(first) < 48 and first.isupper()) else "paragraph"
        if flow_re.search(text_join) and btype == "title":
            btype = "flow_title"
        blocks.append(
            {
                "type": btype,
                "text": text_join,
                "box": box,
                "words": _words_in_box(words, box),
                "lines": [str(ln.get("t", "")).strip() for ln in current],
            }
        )
        current = []

    for ln in para_lines:
        y = float(ln.get("y", 0))
        if current and prev_y is not None and (y - prev_y) > 0.045:
            flush_para()
        elif current and section_re.match(str(ln.get("t", "")).strip()):
            flush_para()
        current.append(ln)
        prev_y = y
    flush_para()

    # --- Simple table heuristic: 3+ lines with similar multi-column x positions ---
    # Group lines that look like grid rows (multiple short segments on same y).
    y_buckets: dict[float, list[dict]] = {}
    for ln in para_lines:
        yk = round(float(ln.get("y", 0)), 2)
        y_buckets.setdefault(yk, []).append(ln)
    multi_cols = [rows for rows in y_buckets.values() if len(rows) >= 2]
    if len(multi_cols) >= 3:
        # Treat as one table spanning those rows
        all_ln = [ln for rows in multi_cols for ln in rows]
        tbox = _union_box([_line_box(ln) for ln in all_ln])
        # Build rough cells by sorting unique x bands
        xs = sorted({round(float(ln.get("x", 0)), 2) for ln in all_ln})
        # Merge close x bands
        cols: list[float] = []
        for x in xs:
            if not cols or abs(x - cols[-1]) > 0.08:
                cols.append(x)
        cells = []
        for ri, rows in enumerate(sorted(multi_cols, key=lambda r: float(r[0].get("y", 0)))):
            for ln in sorted(rows, key=lambda r: float(r.get("x", 0))):
                lb = _line_box(ln)
                col = 0
                for ci, cx in enumerate(cols):
                    if abs(float(ln.get("x", 0)) - cx) <= 0.08:
                        col = ci
                        break
                cells.append(
                    {
                        "r": ri,
                        "c": col,
                        "text": str(ln.get("t", "")).strip(),
                        "box": lb,
                        "words": _words_in_box(words, lb),
                    }
                )
        if cells:
            tables.append(
                {
                    "type": "table",
                    "rows": len(multi_cols),
                    "cols": max(1, len(cols)),
                    "box": tbox,
                    "cells": cells,
                }
            )

    # --- Figure / flow region: large vertical gap under a flow title ---
    for i, blk in enumerate(blocks):
        if blk.get("type") != "flow_title":
            continue
        y0 = float(blk["box"]["y"] + blk["box"]["h"])
        y1 = 0.82
        for nxt in blocks[i + 1 :]:
            if nxt.get("type") in ("title", "flow_title", "paragraph"):
                y1 = min(y1, float(nxt["box"]["y"]))
                break
        if y1 - y0 < 0.06:
            continue
        fbox = {"x": 0.05, "y": y0, "w": 0.9, "h": max(y1 - y0, 0.08)}
        fwords = _words_in_box(words, fbox)
        # Node-like short phrases
        nodes = []
        for ln in para_lines:
            lb = _line_box(ln)
            cy = lb["y"] + lb["h"] / 2
            if fbox["y"] <= cy <= fbox["y"] + fbox["h"]:
                tt = str(ln.get("t", "")).strip()
                if 2 <= len(tt) <= 48:
                    nodes.append(
                        {
                            "text": tt,
                            "box": lb,
                            "words": _words_in_box(words, lb),
                        }
                    )
        figures.append(
            {
                "type": "flow",
                "box": fbox,
                "words": fwords,
                "nodes": nodes,
                "text": " | ".join(n["text"] for n in nodes),
            }
        )

    return {
        "blocks": blocks,
        "tables": tables,
        "signatures": signatures,
        "footers": footers,
        "figures": figures,
    }


if __name__ == "__main__":
    main()
