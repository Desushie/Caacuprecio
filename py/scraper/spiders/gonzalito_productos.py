import json
import re
import scrapy
from scraper.items import ProductoItem
from scraper.utils.brands import extract_brand
from scraper.utils.categories import extract_category


class GonzalitoProductosSpider(scrapy.Spider):
    name = "gonzalito_productos"
    store_name = "Tienda Gonzalito"
    allowed_domains = ["tiendagonzalito.com.py", "www.tiendagonzalito.com.py"]
    start_urls = [
        "https://www.tiendagonzalito.com.py/categoria/74/celulares",
        "https://www.tiendagonzalito.com.py/categoria/51/televisores",
        "https://www.tiendagonzalito.com.py/categoria/282/refrigeracion",
        "https://www.tiendagonzalito.com.py/categoria/99/climatizacion",
        "https://www.tiendagonzalito.com.py/categoria/5/coccion",
        "https://www.tiendagonzalito.com.py/categoria/23/audio-y-sonido",
        "https://www.tiendagonzalito.com.py/categoria/26/pequenos-electrodomesticos",
        "https://www.tiendagonzalito.com.py/categoria/28/lavado",
        "https://www.tiendagonzalito.com.py/categoria/9/muebles",
        "https://www.tiendagonzalito.com.py/categoria/699/bienestar-y-ocio",
        "https://www.tiendagonzalito.com.py/categoria/701/industriales-y-herramientas",
        "https://www.tiendagonzalito.com.py/categoria/137/tecnologia",
        "https://www.tiendagonzalito.com.py/categoria/60/cuidado-personal",
    ]

    custom_settings = {
        "DOWNLOAD_DELAY": 0.35,
        "ROBOTSTXT_OBEY": False,
    }

    def parse(self, response):
        categoria_origen = self.clean_text(
            response.css("h1::text").get()
            or response.css("title::text").get(default="")
        )
        vistos = set()

        # Productos dentro del listado
        for href in response.css('a[href*="/producto/"]::attr(href)').getall():
            href = response.urljoin((href or "").strip())
            href = href.split("?")[0].rstrip("/")
            if not href or href in vistos:
                continue
            vistos.add(href)
            yield response.follow(
                href,
                callback=self.parse_producto,
                meta={"categoria_origen": categoria_origen},
            )

        # Algunas páginas incluyen "Ver todo" y otros enlaces de categoría.
        # No seguimos más categorías para evitar bucles; solo paginación real.
        next_page = self.find_next_page(response)
        if next_page:
            yield response.follow(next_page, callback=self.parse)

    def parse_producto(self, response):
        nombre = self.clean_text(
            response.css("h1::text").get()
            or response.css("meta[property='og:title']::attr(content)").get()
            or response.css("title::text").get(default="")
        )
        if not nombre:
            return

        body_text = self.clean_text(" ".join(
            t.strip() for t in response.css("body ::text").getall() if t.strip()
        ))

        precio = self.parse_precio(response, body_text)
        if precio is None:
            return

        categoria = self.extraer_categoria(response, nombre, response.meta.get("categoria_origen", ""))
        marca = self.extraer_marca(response, nombre, body_text)
        descripcion = self.extraer_descripcion(response, nombre)
        imagen = self.extraer_imagen(response)
        stock = self.extraer_stock(body_text)

        item = ProductoItem()
        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url.split("?")[0].rstrip("/")
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = stock
        item["imagen"] = imagen
        item["marca"] = marca
        item["descripcion"] = descripcion
        yield item

    def find_next_page(self, response):
        # Rel next o paginación explícita
        next_page = (
            response.css('a[rel="next"]::attr(href)').get()
            or response.css("a.next::attr(href)").get()
            or response.css("li.next a::attr(href)").get()
        )
        if next_page:
            return next_page

        # Buscar anclas con texto de siguiente
        for a in response.css("a"):
            text = self.clean_text(" ".join(a.css("::text").getall())).lower()
            href = (a.attrib.get("href") or "").strip()
            if not href:
                continue
            if any(x in text for x in ["siguiente", "next", "›", ">"]):
                return href
        return None

    def parse_precio(self, response, body_text):
        # 1) JSON-LD / scripts
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            for price in self._prices_from_jsonld(raw):
                value = self.to_int(price)
                if value:
                    return value

        # 2) Metas/atributos comunes
        candidates = response.css(
            '[itemprop="price"]::attr(content), '
            'meta[property="product:price:amount"]::attr(content), '
            'meta[name="price"]::attr(content), '
            '[data-price]::attr(data-price)'
        ).getall()
        for candidate in candidates:
            value = self.to_int(candidate)
            if value:
                return value

        # 3) Texto visible: preferir contado / ahora / oferta
        patrones = [
            r"(?:al contado ahora|al contado|oferta)\s*gs\.\s*([\d\.]+)",
            r"gs\.\s*([\d\.]+)",
        ]
        for patron in patrones:
            m = re.search(patron, body_text, re.I)
            if m:
                value = self.to_int(m.group(1))
                if value:
                    return value
        return None

    def extraer_categoria(self, response, nombre, categoria_origen=""):
        candidatos = []

        # Breadcrumb: en snippets se observa Inicio > Categoría > Producto
        breadcrumbs = [
            self.clean_text(t)
            for t in response.css(
                '.breadcrumb *::text, [class*="breadcrumb"] *::text, nav *::text'
            ).getall()
            if self.clean_text(t)
        ]
        candidatos.extend(breadcrumbs)

        # JSON-LD
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            for cat in self._search_json_keys(data, {"category", "articleSection"}):
                candidatos.append(self.clean_text(cat))

        if categoria_origen:
            candidatos.append(self.clean_text(categoria_origen))

        cat_nombre = self.clean_text(extract_category(nombre))
        if cat_nombre:
            candidatos.append(cat_nombre)

        ignorar = {
            "", "inicio", "home", "catalogo", "catálogo", "producto", "productos",
            "descripcion", "descripción", "ver todo", "tienda gonzalito", "tu solución",
            nombre.lower(), "sin categoría", "sin categoria", "uncategorized"
        }
        for cat in candidatos:
            cat_clean = self.clean_text(cat)
            if not cat_clean:
                continue
            cat_l = cat_clean.lower()
            if cat_l in ignorar:
                continue
            if len(cat_clean) < 3:
                continue
            return cat_clean
        return "Otros"

    def extraer_marca(self, response, nombre, body_text):
        # JSON-LD marca
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            for brand in self._extract_brand_from_jsonld(data):
                brand = self.clean_text(brand)
                if brand:
                    return brand

        # Metas visibles
        metas = response.css(
            'meta[property="og:title"]::attr(content), meta[name="keywords"]::attr(content)'
        ).getall()
        for txt in metas:
            brand = self.clean_text(extract_brand(txt))
            if brand:
                return brand

        brand = self.clean_text(extract_brand(nombre))
        if brand:
            return brand

        brand = self.clean_text(extract_brand(f"{nombre} {body_text[:250]}"))
        if brand:
            return brand

        return "Genérico"

    def extraer_stock(self, body_text):
        text = body_text.lower()
        if any(x in text for x in ["sin stock", "agotado", "no disponible"]):
            return "Sin stock"
        if "en stock" in text:
            return "En stock"
        return "Consultar stock"

    def extraer_imagen(self, response):
        candidatos = []
        for sel in [
            'meta[property="og:image"]::attr(content)',
            'meta[name="twitter:image"]::attr(content)',
            'meta[itemprop="image"]::attr(content)',
            'img[src*="/producto/"]::attr(src)',
            'img[src*="/uploads/"]::attr(src)',
            'img::attr(src)',
            'img::attr(data-src)',
            'img::attr(data-lazy-src)',
        ]:
            candidatos.extend(response.css(sel).getall())

        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            for image in self._search_json_keys(data, {"image", "thumbnailUrl"}):
                if isinstance(image, str):
                    candidatos.append(image)
                elif isinstance(image, list):
                    candidatos.extend([x for x in image if isinstance(x, str)])

        for img in candidatos:
            img = (img or "").strip()
            if not img or img.startswith("data:image"):
                continue
            if img.startswith("//"):
                img = "https:" + img
            return response.urljoin(img)
        return ""

    def extraer_descripcion(self, response, nombre):
        # 1) JSON-LD description
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            descs = self._search_json_keys(data, {"description"})
            for desc in descs:
                if isinstance(desc, str):
                    cleaned = self.clean_description(desc, nombre)
                    if cleaned:
                        return cleaned[:1200]

        # 2) Bloque entre "Descripción" y "Productos que te pueden interesar"
        textos = [self.clean_text(t) for t in response.css("body ::text").getall()]
        textos = [t for t in textos if t]

        inicio = None
        fin = None
        for i, t in enumerate(textos):
            tl = t.lower()
            if inicio is None and tl in {"descripción", "descripcion"}:
                inicio = i + 1
                continue
            if inicio is not None and (
                "productos que te pueden interesar" in tl
                or "información" in tl
                or "informacion" in tl
                or "métodos de pago" in tl
                or "metodos de pago" in tl
            ):
                fin = i
                break

        if inicio is not None:
            chunk = textos[inicio:fin]
            cleaned = self.clean_description(" ".join(chunk), nombre)
            if cleaned:
                return cleaned[:1200]

        # 3) Fallback por selectores frecuentes
        desc = " ".join(
            t.strip()
            for t in response.css(
                ".description *::text, .product-description *::text, .tab-content *::text, .content *::text"
            ).getall()
            if t.strip()
        )
        desc = self.clean_description(desc, nombre)
        return desc[:1200] if desc else ""

    def clean_description(self, text, nombre=""):
        text = self.clean_text(text)
        if not text:
            return ""

        # cortar basura del footer o recomendaciones
        cortes = [
            "productos que te pueden interesar",
            "información", "informacion",
            "métodos de pago", "metodos de pago",
            "todo franquicia", "todos los derechos reservados",
            "sitio web comercial adaptado",
            "luis alberto del paraná", "luis alberto del parana",
            "seguinos", "suscribite a nuestro newsletter",
        ]
        lower = text.lower()
        cut_at = len(text)
        for marker in cortes:
            pos = lower.find(marker)
            if pos != -1:
                cut_at = min(cut_at, pos)
        text = text[:cut_at]

        # sacar repetición inicial del nombre
        if nombre:
            nombre_clean = self.clean_text(nombre)
            if text.lower().startswith(nombre_clean.lower()):
                text = text[len(nombre_clean):].strip(" -:|\n\t")

        # limpiar cuotas, precios y ruidos comunes
        text = re.sub(r"\b\d+\s*cuotas?\s+de\s+gs\.\s*[\d\.]+", " ", text, flags=re.I)
        text = re.sub(r"\b(?:o\s+al\s+contado|al\s+contado|oferta)\b", " ", text, flags=re.I)
        text = re.sub(r"https?://\S+", " ", text)
        text = re.sub(r"\bGs\.\s*[\d\.]+", " ", text, flags=re.I)
        text = re.sub(r"\s+", " ", text).strip(" -|:\n\t")
        return text

    def _prices_from_jsonld(self, raw):
        prices = []
        try:
            data = json.loads(raw)
        except Exception:
            return prices

        for value in self._search_json_keys(data, {"price", "lowPrice", "highPrice"}):
            prices.append(value)
        return prices

    def _extract_brand_from_jsonld(self, data):
        encontrados = []
        if isinstance(data, dict):
            for key, value in data.items():
                key_l = str(key).lower()
                if key_l == "brand":
                    if isinstance(value, str):
                        encontrados.append(value)
                    elif isinstance(value, dict):
                        for sub in ["name", "@value"]:
                            v = value.get(sub)
                            if isinstance(v, str):
                                encontrados.append(v)
                    elif isinstance(value, list):
                        for item in value:
                            encontrados.extend(self._extract_brand_from_jsonld(item))
                else:
                    encontrados.extend(self._extract_brand_from_jsonld(value))
        elif isinstance(data, list):
            for item in data:
                encontrados.extend(self._extract_brand_from_jsonld(item))
        return encontrados

    def _search_json_keys(self, data, keys):
        encontrados = []
        keys_l = {k.lower() for k in keys}
        if isinstance(data, dict):
            for key, value in data.items():
                if str(key).lower() in keys_l:
                    encontrados.append(value)
                else:
                    encontrados.extend(self._search_json_keys(value, keys))
        elif isinstance(data, list):
            for item in data:
                encontrados.extend(self._search_json_keys(item, keys))
        return encontrados

    def to_int(self, value):
        if value is None:
            return None
        if isinstance(value, (int, float)):
            return int(float(value))
        text = str(value).strip()
        if not text:
            return None
        # 1.636.000 o 1636000
        m = re.search(r"(\d[\d\.]*)", text)
        if not m:
            return None
        try:
            return int(m.group(1).replace(".", ""))
        except Exception:
            return None

    def clean_text(self, text):
        if not text:
            return ""
        text = re.sub(r"<[^>]+>", " ", str(text))
        text = text.replace("\xa0", " ")
        text = re.sub(r"\s+", " ", text)
        return text.strip(" -\n\t\r")
