import asyncio
import json
import re
import scrapy

from scraper.items import ProductoItem
from scraper.utils.brands import extract_brand
from scraper.utils.categories import extract_category


class InverfinProductosSpider(scrapy.Spider):
    """
    Inverfin es una tienda Shopify que limita las peticiones por IP (429).
    En vez de abrir ~1.400 páginas de producto, se lee el JSON público de cada
    colección (/collections/<handle>/products.json), que trae hasta 250
    productos por página con precio, stock, imagen y descripción.
    """

    name = "inverfin_productos"
    store_name = "Inverfin"
    allowed_domains = ["inverfin.com.py", "www.inverfin.com.py"]

    base_url = "https://inverfin.com.py"
    page_size = 250
    max_rate_limit_waits = 6
    default_retry_after = 60

    collections = {
        "motos": "Motos",
        "tv-y-audio": "TV y Audio",
        "salud-y-belleza": "Salud y Belleza",
        "deportes": "Deportes",
        "climatizacion": "Climatización",
        "smartphones": "Smartphones",
        "tecnologia": "Tecnología",
        "electrodomesticos": "Electrodomésticos",
    }

    # Los 429 los maneja el spider respetando Retry-After; el RetryMiddleware
    # reintentaría al instante y solo alargaría el bloqueo.
    handle_httpstatus_list = [429]

    custom_settings = {
        "DOWNLOAD_DELAY": 3,
        "RANDOMIZE_DOWNLOAD_DELAY": True,
        "CONCURRENT_REQUESTS": 1,
        "CONCURRENT_REQUESTS_PER_DOMAIN": 1,
        "AUTOTHROTTLE_ENABLED": False,
        "RETRY_TIMES": 4,
        "RETRY_HTTP_CODES": [500, 502, 503, 504, 522, 524, 408],
        "DEFAULT_REQUEST_HEADERS": {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
            "Accept": "application/json,text/plain,*/*",
            "Accept-Language": "es-ES,es;q=0.9,en;q=0.8",
        },
    }

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.seen_handles = set()

    async def start(self):
        for handle, title in self.collections.items():
            yield self.collection_request(handle, title, 1)

    def collection_request(self, handle, title, page, waits=0):
        return scrapy.Request(
            f"{self.base_url}/collections/{handle}/products.json?limit={self.page_size}&page={page}",
            callback=self.parse_collection,
            dont_filter=True,
            meta={"collection": handle, "categoria_origen": title, "page": page, "rl_waits": waits},
        )

    async def parse_collection(self, response):
        meta = response.meta

        if response.status == 429:
            waits = meta["rl_waits"] + 1
            if waits > self.max_rate_limit_waits:
                self.logger.error(f"[{meta['collection']} p{meta['page']}] Inverfin sigue limitando (429); se omite.")
                return
            delay = self.retry_after(response)
            self.logger.warning(f"[{meta['collection']} p{meta['page']}] 429: esperando {delay} s ({waits}/{self.max_rate_limit_waits})")
            await asyncio.sleep(delay)
            yield self.collection_request(meta["collection"], meta["categoria_origen"], meta["page"], waits)
            return

        try:
            products = json.loads(response.text).get("products") or []
        except ValueError:
            self.logger.error(f"[{response.url}] respuesta sin JSON válido")
            return

        self.logger.info(f"[{meta['collection']} p{meta['page']}] {len(products)} productos")

        for product in products:
            handle = (product.get("handle") or "").strip()
            if not handle or handle in self.seen_handles:
                continue
            self.seen_handles.add(handle)

            item = self.build_item(product, meta["categoria_origen"])
            if item:
                yield item

        if len(products) >= self.page_size:
            yield self.collection_request(meta["collection"], meta["categoria_origen"], meta["page"] + 1)

    def retry_after(self, response):
        raw = response.headers.get("Retry-After")
        try:
            return max(5, min(300, int(float(raw.decode()))))
        except (AttributeError, ValueError):
            return self.default_retry_after

    def build_item(self, product, categoria_origen):
        nombre = self.clean_product_title(product.get("title"))
        if not nombre or not self.is_valid_product_name(nombre):
            return None

        variants = product.get("variants") or []
        precio = self.variant_price(variants)
        if precio is None:
            return None
        if self.precio_en_dolares(precio, product.get("product_type"), categoria_origen):
            precio = int(round(precio * self.settings.getfloat("USD_PYG_RATE", 5715)))

        body_text = self.clean_html_text(product.get("body_html"))
        descripcion = self.clean_description(body_text, nombre)
        if not self.is_good_description(descripcion):
            descripcion = ""

        images = product.get("images") or []
        imagen = (images[0].get("src") or "").strip() if images else ""
        if imagen.startswith("//"):
            imagen = "https:" + imagen

        marca = self.extract_brand_value(nombre, body_text, product.get("vendor"))
        tipo = self.clean_text(product.get("product_type"))
        if categoria_origen == "Motos" and tipo.lower() == "accesorios":
            tipo = "Accesorios de motos"
        categoria = self.map_categoria_inverfin(
            nombre=nombre,
            categoria_producto=tipo,
            categoria_origen=categoria_origen,
            marca=marca,
        )

        item = ProductoItem()
        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = f"{self.base_url}/products/{product['handle'].strip()}"
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = "En stock" if any(v.get("available") for v in variants) else "Consultar stock"
        item["imagen"] = imagen
        item["marca"] = marca
        item["descripcion"] = descripcion[:1500]

        return self.normalizar_item(item)

    def variant_price(self, variants):
        """Precio de venta (Gs.) de la primera variante disponible, o de la primera."""
        ordered = [v for v in variants if v.get("available")] + list(variants)
        for variant in ordered:
            try:
                value = int(round(float(str(variant.get("price")).replace(",", "."))))
            except (TypeError, ValueError):
                continue
            if value >= 1000:
                return value
        return None

    def precio_en_dolares(self, precio, tipo, categoria_origen):
        """Inverfin carga algunas motos en USD (la web muestra "$ 8.790"), pero
        el JSON no trae la moneda: llega price=8790 igual que un precio en Gs.
        Ninguna moto en guaraníes cuesta menos de Gs. 100.000."""
        es_moto = categoria_origen == "Motos" and "moto" in (tipo or "").lower()
        return es_moto and precio < 100_000

    def map_categoria_inverfin(self, nombre, categoria_producto="", categoria_origen="", marca=""):
        """
        Normaliza la categoría de Inverfin hacia las categorías maestras.

        categoria_producto es el product_type de Shopify (p. ej. "LAVARROPAS",
        "MOTOS NACIONALES - 0KM"). No se guarda crudo: primero se pasa por
        extract_category(prefer_keywords=False) para respetar aliases como
        Smartwatch -> Celulares y Smartphones, Anafes -> Electrodomésticos, etc.
        Si no hay alias ni keywords, se usa la colección de origen.
        """
        categoria_producto = self.clean_text(categoria_producto)
        categoria_origen = self.clean_text(categoria_origen)

        # Reglas fuertes por nombre para corregir casos donde la colección/
        # breadcrumb venga de una campaña o categoría equivocada.
        categoria_fuerte = self.category_by_strong_name(nombre)
        if categoria_fuerte:
            return categoria_fuerte

        if categoria_producto:
            categoria_mapeada = extract_category(
                nombre=nombre,
                categoria_original=categoria_producto,
                marca=marca,
                prefer_keywords=False,
            )

            if categoria_mapeada and categoria_mapeada != "Productos":
                return categoria_mapeada

            # Si el tipo no tiene alias útil, usamos keywords del producto
            # como respaldo antes de caer en la colección de origen.
            categoria_por_producto = extract_category(
                nombre=nombre,
                categoria_original=categoria_producto,
                marca=marca,
                prefer_keywords=True,
            )
            if categoria_por_producto and categoria_por_producto != "Productos":
                return categoria_por_producto

        return extract_category(
            nombre=nombre,
            categoria_original=categoria_origen,
            marca=marca,
            prefer_keywords=True,
        )

    def category_by_strong_name(self, nombre):
        """
        Reglas de alta confianza por nombre de producto.
        Solo incluye casos que suelen venir mal categorizados por colecciones
        promocionales o categorías demasiado amplias.
        """
        low = self.clean_text(nombre).lower()

        if not low:
            return ""

        if re.search(r"\b(notebook|laptop|macbook|ideapad|thinkpad|vivobook|inspiron|pavilion|elitebook|chromebook)\b", low):
            return "Informática"

        if re.search(r"\b(placa\s+infrarroja|placa\s+de\s+cocina|anafe|cocina\s+infrarroja)\b", low):
            return "Electrodomésticos"

        if re.search(r"\b(smartwatch|smart\s+watch|reloj\s+inteligente|reloj)\b", low):
            return "Celulares y Smartphones"

        if re.search(r"\b(soporte\s+para\s+tv|soporte\s+tv)\b", low):
            return "Accesorios"

        return ""

    def clean_product_title(self, text):
        text = self.clean_text(text)
        if not text:
            return ""

        # Quitar sufijos típicos del title/meta.
        text = re.sub(r"\s*[-|–]\s*Inverfin.*$", "", text, flags=re.I)
        text = re.sub(r"\s*[-|–]\s*Compra.*$", "", text, flags=re.I)
        text = re.sub(r"\s*\|\s*Inverfin.*$", "", text, flags=re.I)
        text = re.sub(r"\s+", " ", text).strip(" -|–")

        return text

    def is_valid_product_name(self, name):
        low = self.clean_text(name).lower()

        bad_exact = {
            "inverfin",
            "productos",
            "producto",
            "carrito",
            "buscar",
            "inicio",
            "saltar al contenido",
            "no se pudo cargar la disponibilidad de recogida",
        }

        if not low or low in bad_exact:
            return False
        if len(low) < 4:
            return False
        if low.startswith("compartir en "):
            return False

        return True

    # ---------- descripción ----------
    def clean_description(self, text, nombre=""):
        text = self.clean_text(text)
        if not text:
            return ""

        # eliminar basura conocida al inicio
        text = re.sub(r'^\s*saltar al contenido\s*', '', text, flags=re.I)
        text = re.sub(r'^\s*facebook\s+instagram\s+sucursales\s+trabaja\s+con\s+nosotros\s*', '', text, flags=re.I)
        text = re.sub(r'^\s*021\s*288\s*3000\s*', '', text, flags=re.I)
        text = re.sub(r'^\s*en\s+todos\s+los\s+productos\s*021\s*288\s*3000\s*', '', text, flags=re.I)
        text = re.sub(r'^\s*impuestos\s+incluidos\.?\s*', '', text, flags=re.I)
        text = re.sub(r'^\s*env[ií]o\s+calculado\s+al\s+finalizar\s+la\s+compra\.?\s*', '', text, flags=re.I)

        lower = text.lower()
        cuts = [
            "saltar al contenido",
            "facebook instagram sucursales trabaja con nosotros",
            "en todos los productos 021 288 3000",
            "021 288 3000 tus compras",
            "021 288 3000",
            "tus compras estan protegidas",
            "tus compras están protegidas",
            "productos más buscados",
            "productos mas buscados",
            "palabras clave más buscadas",
            "palabras clave mas buscadas",
            "lo más vendido",
            "lo mas vendido",
            "también te puede interesar",
            "tambien te puede interesar",
            "productos relacionados",
            "suscribite",
            "seguinos",
            "todos los derechos reservados",
            "impuestos incluidos",
            "envío calculado al finalizar la compra",
            "envio calculado al finalizar la compra",
            "no se pudo cargar la disponibilidad de recogida",
            "refrescar",
            "compartir:",
            "compartir en facebook",
            "compartir en x",
        ]
        cut_at = len(text)
        for marker in cuts:
            pos = lower.find(marker)
            if pos != -1:
                cut_at = min(cut_at, pos)
        text = text[:cut_at]

        if nombre:
            nombre_clean = self.clean_text(nombre)
            if text.lower().startswith(nombre_clean.lower()):
                text = text[len(nombre_clean):].strip(" -:|")

        text = re.sub(r"https?://\S+", " ", text)
        text = re.sub(r"\b\d+\s*cuotas?\s+de\s+Gs\.\s*[\d\.]+\b", " ", text, flags=re.I)
        text = re.sub(r"\bGs\.\s*[\d\.]+\b", " ", text, flags=re.I)
        text = re.sub(r"\s+", " ", text).strip(" -:|")
        return text

    def is_good_description(self, text):
        if not text:
            return False

        low = text.lower().strip()
        bad_exact = {
            "021 288 3000",
            "impuestos incluidos",
            "envío calculado al finalizar la compra",
            "envio calculado al finalizar la compra",
            "saltar al contenido facebook instagram sucursales trabaja con nosotros",
        }
        if low in bad_exact:
            return False
        if len(low) < 25:
            return False
        if re.fullmatch(r'[\d\s\-\(\)]+', low):
            return False
        # descartar frases claramente de header/nav
        bad_fragments = [
            "saltar al contenido",
            "facebook instagram",
            "sucursales trabaja con nosotros",
        ]
        if any(b in low for b in bad_fragments):
            return False
        return True

    # ---------- marca ----------
    def extract_brand_value(self, nombre, body_text, vendor):
        # Primero usar el nombre real ya corregido. Evita que un JSON viejo
        # marque todos los productos como TAIGA u otra marca incorrecta.
        brand = self.clean_text(extract_brand(nombre))
        if brand:
            return brand

        # Buscar una marca visible en el texto del producto, si existe.
        m = re.search(r"\bMarca\s*:\s*([A-Za-zÁÉÍÓÚÑáéíóúñ0-9\s\.\-]+)", body_text, re.I)
        if m:
            candidate = self.clean_text(m.group(1))
            candidate = re.split(
                r"\b(Modelo|Color|Precio|Ficha|Stock|Compartir|Cantidad|Código|Codigo)\b",
                candidate,
                maxsplit=1,
                flags=re.I,
            )[0]
            candidate = self.clean_text(candidate)
            if candidate and 2 <= len(candidate) <= 40:
                return candidate

        # Shopify trae el vendor en mayúsculas ("MUELLER"); las siglas cortas
        # (LG, JBL, TCL) se dejan como están.
        brand = self.clean_text(vendor)
        if brand:
            return brand.title() if brand.isupper() and len(brand) > 3 else brand

        return "Genérico"

    # ---------- utilidades ----------
    def normalizar_item(self, item):
        marca = self.clean_text(item.get("marca") or "")
        if not marca or marca.lower() in {"sin marca", "no brand", "n/a", "na"}:
            marca_detectada = extract_brand(item.get("nombre") or "")
            item["marca"] = self.clean_text(marca_detectada) if marca_detectada else "Genérico"
        else:
            item["marca"] = marca

        categoria = self.clean_text(item.get("categoria") or "")
        if categoria:
            categoria_mapeada = extract_category(
                nombre=item.get("nombre") or "",
                categoria_original=categoria,
                marca=item.get("marca") or "",
                prefer_keywords=False,
            )
            if categoria_mapeada and categoria_mapeada != "Productos":
                item["categoria"] = categoria_mapeada
            else:
                item["categoria"] = extract_category(
                    nombre=item.get("nombre") or "",
                    categoria_original=categoria,
                    marca=item.get("marca") or "",
                    prefer_keywords=True,
                ) or "Productos"
        else:
            item["categoria"] = extract_category(item.get("nombre") or "") or "Productos"
        return item

    def clean_html_text(self, html):
        if not html:
            return ""
        text = re.sub(r"<[^>]+>", " ", str(html))
        text = text.replace("\xa0", " ")
        text = re.sub(r"\s+", " ", text).strip()
        return text[:1500]

    def clean_text(self, text):
        if not text:
            return ""
        text = re.sub(r"<[^>]+>", " ", str(text))
        text = text.replace("\xa0", " ")
        text = re.sub(r"\s+", " ", text)
        return text.strip(" -\n\t\r")
