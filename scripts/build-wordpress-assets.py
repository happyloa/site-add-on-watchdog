#!/usr/bin/env python3
"""Build exact WordPress.org icon and banner sizes from tracked masters."""

from pathlib import Path

from PIL import Image, ImageOps


ROOT = Path(__file__).resolve().parents[1]
MASTER_DIR = ROOT / "artwork" / "masters"
OUTPUT_DIR = ROOT / "wordpress-org-assets"


def save_icon(master: Image.Image, size: int) -> Path:
    output = OUTPUT_DIR / f"icon-{size}x{size}.png"
    resized = master.resize((size, size), Image.Resampling.LANCZOS)
    resized.save(output, format="PNG", optimize=True, compress_level=9)
    return output


def save_banner(master: Image.Image, width: int, height: int) -> Path:
    output = OUTPUT_DIR / f"banner-{width}x{height}.png"
    fitted = ImageOps.fit(
        master,
        (width, height),
        method=Image.Resampling.LANCZOS,
        centering=(0.5, 0.5),
    )
    fitted.save(output, format="PNG", optimize=True, compress_level=9)
    return output


def main() -> None:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    with Image.open(MASTER_DIR / "icon-master-transparent.png") as image:
        icon = image.convert("RGBA")
        alpha_min, alpha_max = icon.getchannel("A").getextrema()
        if alpha_min != 0 or alpha_max != 255:
            raise RuntimeError("Icon master must contain transparent and opaque pixels.")
        outputs = [save_icon(icon, 128), save_icon(icon, 256)]

    with Image.open(MASTER_DIR / "banner-master.png") as image:
        banner = image.convert("RGB")
        outputs.extend([
            save_banner(banner, 772, 250),
            save_banner(banner, 1544, 500),
        ])

    for output in outputs:
        with Image.open(output) as image:
            print(f"{output.relative_to(ROOT)}: {image.width}x{image.height}, {output.stat().st_size} bytes")


if __name__ == "__main__":
    main()
