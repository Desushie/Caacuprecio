import re
import unicodedata
from typing import Optional, Dict, List, Tuple


# ============================================================
# CATEGORÍAS MAESTRAS
# ============================================================
# Estas son las categorías finales que querés usar en tu sistema.
CATEGORIAS_MASTER = [
    "Celulares y Smartphones",
    "Tablets",
    "Informática",
    "Audio",
    "TV y Video",
    "Gaming",
    "Accesorios",
    "Redes y Conectividad",
    "Cámaras y Seguridad",
    "Electrodomésticos",
    "Climatización",
    "Herramientas",
    "Oficina",
    "Hogar",
    "Salud y Belleza",
    "Deportes",
    "Motocicletas",
    "Bebés y Juguetes",
    "Outlet",
    "Productos",
]


# ============================================================
# VARIANTES DE CATEGORÍAS DE LAS TIENDAS
# ============================================================
# Mapea nombres de categorías del sitio a una categoría maestra.
# Las claves deben estar ya normalizadas por normalize_text().
CATEGORY_ALIASES: Dict[str, str] = {
    "accesorios": "Accesorios",
    "audio": "Audio",
    "bebes y juguetes": "Bebés y Juguetes",
    "camaras y seguridad": "Cámaras y Seguridad",
    "celulares": "Celulares y Smartphones",
    "celulares y accesorios": "Celulares y Smartphones",
    "celulares y smartphones": "Celulares y Smartphones",
    "celulares y smartwatches": "Celulares y Smartphones",
    "climatizacion": "Climatización",
    "cuidado personal": "Salud y Belleza",
    "deporte y aire libre": "Deportes",
    "deportes": "Deportes",
    "deportes y aire libre": "Deportes",
    "electrodomesticos": "Electrodomésticos",
    "equipate": "Productos",
    "gaming": "Gaming",
    "herramientas": "Herramientas",
    "herramientas maquinas y equipos": "Herramientas",
    "hogar": "Hogar",
    "infantiles": "Bebés y Juguetes",
    "informatica": "Informática",
    "moto": "Motocicletas",
    "motocicletas": "Motocicletas",
    "motos y accesorios": "Motocicletas",
    "muebles y accesorios": "Hogar",
    "oficina": "Oficina",
    "otros": "Productos",
    "outlet": "Outlet",
    "productos": "Productos",
    "redes y conectividad": "Redes y Conectividad",
    "salud y belleza": "Salud y Belleza",
    "sin categoria": "Productos",
    "tablets": "Tablets",
    "tecnologia": "Productos",
    "televisores y audio": "TV y Video",
    "televisores y equipos de audio": "TV y Video",
    "tv y video": "TV y Video",
}


# ============================================================
# PALABRAS CLAVE BASE
# ============================================================
# Partimos de tu archivo original y ampliamos algunas reglas.
CATEGORY_KEYWORDS: Dict[str, List[str]] = {
    "Celulares y Smartphones": [
        "celular", "smartphone", "iphone", "redmi", "galaxy", "motorola",
        "oppo", "honor", "nokia", "xiaomi", "poco", "infinix", "tecno"
    ],
    "Tablets": [
        "tablet", "ipad", "tab "
    ],
    "Informática": [
        "notebook", "laptop", "ultrabook", "pc", "computadora", "monitor",
        "impresora", "teclado", "mouse", "ssd", "disco duro", "memoria ram",
        "pendrive", "ups", "webcam", "microfono", "gabinete", "placa madre",
        "procesador", "all in one", "aio", "scanner", "escaner"
    ],
    "Audio": [
        "parlante", "speaker", "auricular", "headset", "microfono", "soundbar",
        "barra de sonido", "subwoofer", "amplificador", "earbuds", "buds"
    ],
    "TV y Video": [
        "tv", "televisor", "smart tv", "proyector", "chromecast", "roku",
        "google tv", "android tv"
    ],
    "Gaming": [
        "playstation", "ps4", "ps5", "xbox", "nintendo", "joystick",
        "control", "gamepad", "silla gamer", "monitor gamer", "gaming", "gamer"
    ],
    "Accesorios": [
        "cargador", "adaptador", "cable", "funda", "case", "protector",
        "soporte", "power bank", "powerbank", "hub usb", "lector", "dock",
        "vidrio templado", "templado", "cargador inalambrico", "wireless charger"
    ],
    "Redes y Conectividad": [
        "router", "modem", "módem", "access point", "repetidor", "antena",
        "wifi", "network", "switch", "mesh", "tp-link deco"
    ],
    "Cámaras y Seguridad": [
        "camara", "cámara", "cctv", "dvr", "nvr", "seguridad",
        "videovigilancia", "ip camera", "camara ip"
    ],
    "Electrodomésticos": [
        "heladera", "lavarropas", "lavarropa", "microondas", "licuadora",
        "freidora", "aspiradora", "cafetera", "horno", "cocina", "batidora",
        "plancha", "sandwichera", "freezer", "lavavajillas", "extractor"
    ],
    "Climatización": [
        "aire acondicionado", "acondicionado", "ventilador", "calefactor",
        "estufa", "climatizador"
    ],
    "Herramientas": [
        "taladro", "atornillador", "amoladora", "hidrolavadora", "compresor",
        "soldadora", "sierra", "pistola de impacto", "lijadora"
    ],
    "Oficina": [
        "silla", "escritorio", "papel", "papeleria", "papelería", "tinta",
        "toner", "tóner", "resma"
    ],
    "Hogar": [
        "sofa", "sofá", "colchon", "colchón", "sommier", "mesa", "ropero",
        "mueble", "olla", "placard", "rack", "comoda", "cómoda"
    ],
    "Salud y Belleza": [
        "tensiometro", "tensiómetro", "balanza", "secador", "planchita",
        "depiladora", "afeitadora", "masajeador", "perfume", "crema",
        "maquillaje", "cepillo alisador"
    ],
    "Deportes": [
        "bicicleta", "cinta para caminar", "caminadora", "mancuerna",
        "banco de ejercicio", "pelota", "fitness", "pesas", "spinning"
    ],
    "Motocicletas": [
        "moto", "motocicleta", "casco", "cubierta", "buler", "benelli",
        "taiga", "kenton", "leopard"
    ],
    "Bebés y Juguetes": [
        "juguete", "muñeca", "muneca", "bebe", "bebé", "pañal",
        "carrito", "andador", "mamadera"
    ],
}


CATEGORY_ORDER = [
    "Celulares y Smartphones",
    "Tablets",
    "Informática",
    "Audio",
    "TV y Video",
    "Gaming",
    "Accesorios",
    "Redes y Conectividad",
    "Cámaras y Seguridad",
    "Electrodomésticos",
    "Climatización",
    "Herramientas",
    "Oficina",
    "Hogar",
    "Salud y Belleza",
    "Deportes",
    "Motocicletas",
    "Bebés y Juguetes",
]


def remove_accents(text: str) -> str:
    text = unicodedata.normalize("NFD", text)
    return "".join(c for c in text if unicodedata.category(c) != "Mn")


def normalize_text(text: Optional[str]) -> str:
    """
    Normaliza texto para comparar:
    - minúsculas
    - sin tildes
    - símbolos raros afuera
    - espacios colapsados
    """
    text = (text or "").strip().lower()
    text = text.replace("&", " y ")
    text = remove_accents(text)
    text = re.sub(r"[^a-z0-9\s/_-]", " ", text)
    text = re.sub(r"[_/-]+", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text


def category_from_site(categoria_original: Optional[str]) -> Optional[str]:
    """
    Intenta mapear la categoría original del sitio a una categoría maestra.
    """
    categoria_norm = normalize_text(categoria_original)
    if not categoria_norm:
        return None
    return CATEGORY_ALIASES.get(categoria_norm)


def category_from_keywords(
    nombre: Optional[str] = "",
    categoria_original: Optional[str] = "",
    marca: Optional[str] = "",
) -> Optional[str]:
    """
    Detecta la categoría por palabras clave usando nombre, categoría original y marca.
    """
    texto = " ".join([
        normalize_text(nombre),
        normalize_text(categoria_original),
        normalize_text(marca),
    ]).strip()

    if not texto:
        return None

    for category in CATEGORY_ORDER:
        keywords = CATEGORY_KEYWORDS.get(category, [])
        for keyword in keywords:
            keyword_norm = normalize_text(keyword)
            if not keyword_norm:
                continue

            pattern = r"\b" + re.escape(keyword_norm) + r"\b"
            if re.search(pattern, texto):
                return category

    return None


def is_outlet(categoria_original: Optional[str] = "", nombre: Optional[str] = "") -> bool:
    texto = f"{normalize_text(categoria_original)} {normalize_text(nombre)}".strip()
    return "outlet" in texto


def extract_category(
    nombre: Optional[str],
    categoria_original: Optional[str] = "",
    marca: Optional[str] = "",
    prefer_keywords: bool = True,
) -> str:
    """
    Devuelve la categoría final.

    Prioridad:
    1. Outlet
    2. Keywords
    3. Alias de categoría del sitio
    4. Productos
    """
    if is_outlet(categoria_original, nombre):
        return "Outlet"

    if prefer_keywords:
        cat_kw = category_from_keywords(
            nombre=nombre,
            categoria_original=categoria_original,
            marca=marca,
        )
        if cat_kw:
            return cat_kw

    cat_site = category_from_site(categoria_original)
    if cat_site:
        return cat_site

    return "Productos"


def debug_category(
    nombre: Optional[str],
    categoria_original: Optional[str] = "",
    marca: Optional[str] = "",
) -> dict:
    """
    Útil para depuración.
    """
    return {
        "nombre": nombre or "",
        "marca": marca or "",
        "categoria_original": categoria_original or "",
        "categoria_original_normalizada": normalize_text(categoria_original),
        "categoria_por_keywords": category_from_keywords(
            nombre=nombre,
            categoria_original=categoria_original,
            marca=marca,
        ),
        "categoria_por_alias": category_from_site(categoria_original),
        "es_outlet": is_outlet(categoria_original, nombre),
        "categoria_final": extract_category(
            nombre=nombre,
            categoria_original=categoria_original,
            marca=marca,
        ),
    }


if __name__ == "__main__":
    ejemplos: List[Tuple[str, str, str]] = [
        ("Samsung Galaxy A55 256GB", "CELULARES Y ACCESORIOS", "Samsung"),
        ("Smart TV TCL 50 4K Google TV", "TV y Video", "TCL"),
        ("Auricular Xiaomi Redmi Buds 6 Play", "Sin categoría", "Xiaomi"),
        ("Router TP-Link Archer AX12", "Tecnología", "TP-Link"),
        ("Heladera Tokyo 300L", "Outlet", "Tokyo"),
    ]

    for nombre, categoria_original, marca in ejemplos:
        print(debug_category(nombre, categoria_original, marca))
