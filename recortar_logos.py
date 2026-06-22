"""
recortar_logos.py
-----------------
Recorta automáticamente imágenes eliminando el espacio vacío alrededor del logo.
Funciona con fondos TRANSPARENTES (PNG con alpha) y fondos BLANCOS/COLOR SÓLIDO.

Uso:
    python recortar_logos.py                      # recorta todas las imágenes en ./img/
    python recortar_logos.py img/icono.png        # recorta una imagen específica
    python recortar_logos.py --padding 20         # deja 20px de margen alrededor del logo
    python recortar_logos.py --fondo blanco       # fuerza modo fondo blanco (sin alpha)
    python recortar_logos.py --salida ./img_recortadas/  # carpeta de salida distinta

Requisitos:
    pip install Pillow
"""

import argparse
import sys
from pathlib import Path
from PIL import Image, ImageChops


EXTENSIONES = {".png", ".jpg", ".jpeg", ".webp", ".gif"}


def recortar_imagen(ruta: Path, padding: int = 0, forzar_blanco: bool = False) -> Image.Image:
    img = Image.open(ruta)

    # Si tiene canal alpha (transparencia) y no se fuerza modo blanco
    if img.mode in ("RGBA", "LA") and not forzar_blanco:
        # Usamos el canal alpha para detectar el bounding box del contenido
        r, g, b, a = img.convert("RGBA").split()
        bbox = a.getbbox()

        if bbox is None:
            print(f"  ⚠  {ruta.name}: imagen completamente transparente, se omite.")
            return img

    else:
        # Sin alpha: convertimos a RGB y buscamos diferencia respecto al fondo
        rgb = img.convert("RGB")

        # Tomamos el color del pixel (0,0) como color de fondo (esquina superior izquierda)
        color_fondo = rgb.getpixel((0, 0))

        # Creamos una imagen sólida del color de fondo y calculamos la diferencia
        fondo_solido = Image.new("RGB", rgb.size, color_fondo)
        diff = ImageChops.difference(rgb, fondo_solido)

        # El bounding box de la diferencia es el área con contenido real
        bbox = diff.getbbox()

        if bbox is None:
            print(f"  ⚠  {ruta.name}: imagen del mismo color que el fondo, se omite.")
            return img

    # Aplicar padding (margen extra alrededor del logo)
    if padding > 0:
        x1, y1, x2, y2 = bbox
        x1 = max(0, x1 - padding)
        y1 = max(0, y1 - padding)
        x2 = min(img.width,  x2 + padding)
        y2 = min(img.height, y2 + padding)
        bbox = (x1, y1, x2, y2)

    recortada = img.crop(bbox)
    return recortada


def procesar(rutas: list[Path], salida: Path, padding: int, forzar_blanco: bool) -> None:
    salida.mkdir(parents=True, exist_ok=True)
    ok = 0
    errores = 0

    for ruta in rutas:
        if ruta.suffix.lower() not in EXTENSIONES:
            continue

        try:
            print(f"  Procesando: {ruta.name} ({ruta.stat().st_size // 1024} KB) ...", end=" ")
            recortada = recortar_imagen(ruta, padding=padding, forzar_blanco=forzar_blanco)

            destino = salida / ruta.name
            # Guardamos en PNG para preservar transparencia siempre que sea posible
            fmt = "PNG" if ruta.suffix.lower() in {".png", ".webp"} else "JPEG"
            recortada.save(destino, format=fmt)

            print(f"✓  {recortada.size[0]}x{recortada.size[1]}px  →  {destino}")
            ok += 1

        except Exception as e:
            print(f"✗  Error: {e}")
            errores += 1

    print(f"\nListo: {ok} imagen(es) recortada(s), {errores} error(es).")
    if ok > 0:
        print(f"Archivos guardados en: {salida.resolve()}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Recorta logos eliminando fondo vacío.")
    parser.add_argument(
        "archivos", nargs="*",
        help="Rutas a imágenes específicas. Si no se indica, procesa toda la carpeta ./img/"
    )
    parser.add_argument(
        "--padding", type=int, default=0,
        help="Píxeles de margen extra alrededor del logo (default: 0)"
    )
    parser.add_argument(
        "--fondo", choices=["auto", "blanco"], default="auto",
        help="'auto' detecta transparencia primero; 'blanco' fuerza modo color sólido"
    )
    parser.add_argument(
        "--salida", type=str, default=None,
        help="Carpeta de salida (default: sobreescribe los originales en ./img/)"
    )
    args = parser.parse_args()

    forzar_blanco = args.fondo == "blanco"

    # Determinar archivos a procesar
    if args.archivos:
        rutas = [Path(a) for a in args.archivos]
        rutas_invalidas = [r for r in rutas if not r.exists()]
        if rutas_invalidas:
            print(f"Error: no se encontraron: {', '.join(str(r) for r in rutas_invalidas)}")
            sys.exit(1)
    else:
        carpeta_img = Path("./img")
        if not carpeta_img.exists():
            print("No se encontró la carpeta ./img/  — pasá la ruta como argumento:")
            print("  python recortar_logos.py ruta/a/imagen.png")
            sys.exit(1)
        rutas = sorted(carpeta_img.iterdir())

    # Carpeta de salida
    if args.salida:
        salida = Path(args.salida)
    else:
        # Por defecto: sobreescribe en la misma carpeta del primer archivo
        salida = rutas[0].parent if rutas else Path("./img")

    print(f"\nRecortando {len(rutas)} archivo(s) con padding={args.padding}px ...\n")
    procesar(rutas, salida=salida, padding=args.padding, forzar_blanco=forzar_blanco)


if __name__ == "__main__":
    main()
