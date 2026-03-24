import re

CATEGORY_KEYWORDS = {
    "Celulares y Smartphones": [
        "celular", "smartphone", "iphone", "redmi", "galaxy", "motorola", "oppo", "honor", "nokia"
    ],
    "Tablets": [
        "tablet", "ipad"
    ],
    "Informática": [
        "notebook", "laptop", "ultrabook", "pc", "computadora", "monitor", "impresora",
        "teclado", "mouse", "router", "switch", "ssd", "disco duro", "memoria ram",
        "pendrive", "ups", "webcam", "microfono", "gabinete", "placa madre", "procesador"
    ],
    "Audio": [
        "parlante", "speaker", "auricular", "headset", "microfono", "soundbar", "barra de sonido",
        "subwoofer", "amplificador"
    ],
    "TV y Video": [
        "tv", "televisor", "smart tv", "proyector", "chromecast", "roku"
    ],
    "Gaming": [
        "playstation", "ps4", "ps5", "xbox", "nintendo", "joystick", "control", "gamepad",
        "silla gamer", "monitor gamer"
    ],
    "Accesorios": [
        "cargador", "adaptador", "cable", "funda", "case", "protector", "soporte",
        "power bank", "powerbank", "hub usb", "lector", "dock"
    ],
    "Redes y Conectividad": [
        "router", "modem", "módem", "access point", "repetidor", "antena", "wifi", "network"
    ],
    "Cámaras y Seguridad": [
        "camara", "cámara", "cctv", "dvr", "nvr", "seguridad", "videovigilancia"
    ],
    "Electrodomésticos": [
        "heladera", "lavarropas", "microondas", "licuadora", "freidora", "aspiradora",
        "cafetera", "horno", "cocina", "batidora", "plancha", "sandwichera"
    ],
    "Climatización": [
        "aire acondicionado", "acondicionado", "ventilador", "calefactor", "estufa", "climatizador"
    ],
    "Herramientas": [
        "taladro", "atornillador", "amoladora", "hidrolavadora", "compresor", "soldadora",
        "sierra", "pistola de impacto"
    ],
    "Oficina": [
        "silla", "escritorio", "papel", "papeleria", "papelería", "tinta", "toner", "tóner"
    ],
    "Hogar": [
        "sofa", "sofá", "colchon", "colchón", "sommier", "mesa", "ropero", "mueble", "olla"
    ],
    "Salud y Belleza": [
        "tensiometro", "tensiómetro", "balanza", "secador", "planchita", "depiladora",
        "afeitadora", "masajeador"
    ],
    "Deportes": [
        "bicicleta", "cinta para caminar", "caminadora", "mancuerna", "banco de ejercicio",
        "pelota", "fitness"
    ],
    "Motocicletas": [
        "moto", "motocicleta", "casco", "cubierta", "buler", "benelli", "taiga"
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
]


def normalize_text(text: str) -> str:
    text = (text or "").strip().lower()
    text = re.sub(r"\s+", " ", text)
    return text


def extract_category(nombre: str) -> str:
    """
    Detecta la categoría basándose en palabras clave dentro del nombre.
    """
    nombre_norm = normalize_text(nombre)

    for category in CATEGORY_ORDER:
        keywords = CATEGORY_KEYWORDS.get(category, [])
        for keyword in keywords:
            pattern = r"\b" + re.escape(keyword.lower()) + r"\b"
            if re.search(pattern, nombre_norm):
                return category

    return "Productos"