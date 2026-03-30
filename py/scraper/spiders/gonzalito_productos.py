import json
import re
from urllib.parse import urlencode

import scrapy
from parsel import Selector

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
        "DUPEFILTER_DEBUG": True,
        "COOKIES_ENABLED": True,
        "DEFAULT_REQUEST_HEADERS": {
            "accept-language": "es,en;q=0.8",
            "referer": "https://www.tiendagonzalito.com.py/",
        },
    }

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._seen_product_urls = set()
        self._seen_api_pages = set()

    def parse(self, response):
        categoria_origen = self.clean_text(
            response.css("h1::text").get()
            or response.css("meta[property='og:title']::attr(content)").get()
            or response.css("title::text").get(default="")
        )

        categoria_id = self.extract_category_id(response.url)
        if not categoria_id:
            self.logger.warning("No se pudo detectar categoria_id desde %s", response.url)
            return

        yield from self.request_product_page(
            response=response,
            categoria_id=categoria_id,
            page=1,
            categoria_origen=categoria_origen,
        )

    def request_product_page(self, response, categoria_id, page, categoria_origen):
        api_url = self.build_api_url(categoria_id, page)
        if api_url in self._seen_api_pages:
            return
        self._seen_api_pages.add(api_url)

        headers = {
            "accept": "*/*",
            "referer": response.url,
        }

        xsrf = self.get_xsrf_token(response)
        if xsrf:
            headers["x-xsrf-token"] = xsrf

        yield scrapy.Request(
            api_url,
            callback=self.parse_api,
            headers=headers,
            meta={
                "categoria_id": categoria_id,
                "categoria_origen": categoria_origen,
                "api_page": page,
                "referer_url": response.url,
            },
            dont_filter=True,
        )

    def parse_api(self, response):
        categoria_id = response.meta["categoria_id"]
        categoria_origen = response.meta.get("categoria_origen", "")
        page = response.meta.get("api_page", 1)

        product_urls = []
        next_page_hint = None

        content_type = (response.headers.get("Content-Type") or b"").decode("latin1").lower()
        text = response.text or ""

        payload = None
        if "json" in content_type or text[:1] in "[{":
            try:
                payload = json.loads(text)
            except Exception:
                payload = None

        if payload is not None:
            product_urls.extend(self.extract_product_urls_from_json(payload, response))
            next_page_hint = self.detect_next_page_from_json(payload, page)

            html_blocks = self.extract_html_blocks_from_json(payload)
            for block in html_blocks:
                product_urls.extend(self.extract_product_urls_from_html(block, response))
        else:
            product_urls.extend(self.extract_product_urls_from_html(text, response))
            next_page_hint = self.detect_next_page_from_html(text, response, page)

        product_urls = sorted({self.normalize_url(url) for url in product_urls if self.normalize_url(url)})

        self.logger.warning(
            "[API categoria=%s page=%s] productos detectados: %s",
            categoria_id,
            page,
            len(product_urls),
        )

        for url in product_urls:
            if url in self._seen_product_urls:
                continue
            self._seen_product_urls.add(url)
            yield scrapy.Request(
                url,
                callback=self.parse_producto,
                meta={"categoria_origen": categoria_origen},
            )

        if product_urls:
            next_page = next_page_hint or (page + 1)
            fake_response = response.replace(url=response.meta.get("referer_url", response.url))
            yield from self.request_product_page(
                response=fake_response,
                categoria_id=categoria_id,
                page=next_page,
                categoria_origen=categoria_origen,
            )

    def extract_product_urls_from_json(self, data, response):
        found = set()

        def walk(obj):
            if isinstance(obj, dict):
                for key, value in obj.items():
                    key_lower = str(key).lower()

                    if key_lower in {"url", "href", "link", "permalink"} and isinstance(value, str):
                        if "/producto/" in value:
                            found.add(response.urljoin(value.replace("\\/", "/")))

                    if isinstance(value, (dict, list)):
                        walk(value)
                    elif isinstance(value, str) and "/producto/" in value:
                        for match in re.findall(r'https?://[^"\'\s>]+/producto/\d+/[^"\'\s>]+', value.replace("\\/", "/")):
                            found.add(match)
                        for match in re.findall(r'/producto/\d+/[^"\'\s>]+', value.replace("\\/", "/")):
                            found.add(response.urljoin(match))

            elif isinstance(obj, list):
                for item in obj:
                    walk(item)

        walk(data)
        return list(found)

    def extract_html_blocks_from_json(self, data):
        blocks = []

        def walk(obj):
            if isinstance(obj, dict):
                for value in obj.values():
                    if isinstance(value, str) and ("/producto/" in value or "<a" in value or "product" in value.lower()):
                        blocks.append(value)
                    elif isinstance(value, (dict, list)):
                        walk(value)
            elif isinstance(obj, list):
                for item in obj:
                    walk(item)

        walk(data)
        return blocks

    def extract_product_urls_from_html(self, html, response):
        if not html:
            return []

        found = set()
        selector = Selector(text=html)

        hrefs = set()
        css_candidates = [
            'a[href*="/producto/"]::attr(href)',
            '[data-href*="/producto/"]::attr(data-href)',
            '[href*="/producto/"]::attr(href)',
        ]
        for css in css_candidates:
            for href in selector.css(css).getall():
                if href:
                    hrefs.add(href)

        raw = html.replace('\\/', '/')
        patterns = [
            r'https?://[^"\'\s>]+/producto/\d+/[^"\'\s>]+',
            r'/producto/\d+/[^"\'\s>]+',
        ]
        for pattern in patterns:
            for match in re.findall(pattern, raw):
                hrefs.add(match)

        for href in hrefs:
            normalized = self.normalize_url(response.urljoin((href or "").strip()))
            if normalized and "/producto/" in normalized:
                found.add(normalized)

        return list(found)

    def clean_text(self, text):
        if not text:
            return ""
        text = re.sub(r"<[^>]+>", " ", str(text))
        text = text.replace("\xa0", " ")
        text = re.sub(r"\s+", " ", text)
        return text.strip(" -\n\t\r")

    def detect_next_page_from_json(self, data, current_page):
        if isinstance(data, dict):
            for key in ("next_page", "nextPage", "page", "pagina"):
                val = data.get(key)
                if isinstance(val, int) and val > current_page:
                    return val
            meta = data.get("meta") or data.get("pagination") or data.get("paginacion")
            if isinstance(meta, dict):
                current = meta.get("current_page") or meta.get("currentPage") or current_page
                last = meta.get("last_page") or meta.get("lastPage")
                if isinstance(current, int) and isinstance(last, int) and current < last:
                    return current + 1
        return None

    def detect_next_page_from_html(self, html, response, current_page):
        selector = Selector(text=html)
        href = (
            selector.css('a[rel="next"]::attr(href)').get()
            or selector.css('a.next::attr(href)').get()
            or selector.css('li.next a::attr(href)').get()
        )
        if href:
            match = re.search(r'[?&]page=(\d+)', href)
            if match:
                next_page = int(match.group(1))
                if next_page > current_page:
                    return next_page
        return None

    def build_api_url(self, categoria_id, page):
        params = {
            "page": page,
            "categoria": categoria_id,
            "ordenar_por": 3,
            "marcas": "",
            "categorias": "",
            "categorias_top": "",
        }
        return f"https://www.tiendagonzalito.com.py/get-productos?{urlencode(params)}"

    def get_xsrf_token(self, response):
        cookies = response.headers.getlist("Set-Cookie") or []
        for cookie in cookies:
            text = cookie.decode("latin1", errors="ignore")
            match = re.search(r'XSRF-TOKEN=([^;]+)', text)
            if match:
                return match.group(1)

        cookie_header = response.request.headers.get("Cookie")
        if cookie_header:
            text = cookie_header.decode("latin1", errors="ignore")
            match = re.search(r'XSRF-TOKEN=([^;]+)', text)
            if match:
                return match.group(1)
        return None

    def extract_category_id(self, url):
        match = re.search(r'/categoria/(\d+)', url)
        return match.group(1) if match else None

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

        marca = self.extraer_marca(response, nombre, body_text)
        categoria = self.extraer_categoria(
            response,
            nombre,
            response.meta.get("categoria_origen", ""),
            marca,
        )
        descripcion = self.extraer_descripcion(response, nombre)
        imagen = self.extraer_imagen(response)
        stock = self.extraer_stock(body_text)

        item = ProductoItem()
        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = self.normalize_url(response.url)
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = stock
        item["imagen"] = imagen
        item["marca"] = marca
        item["descripcion"] = descripcion
        yield item

    def parse_precio(self, response, body_text):
        # 1) JSON-LD / scripts
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            for price in self._prices_from_jsonld(raw):
                value = self.to_int(price)
                if value:
                    return value

        # 2) Metas/atributos comunes del producto principal
        candidates = response.css(
            '[itemprop="price"]::attr(content), '
            'meta[property="product:price:amount"]::attr(content), '
            'meta[property="og:price:amount"]::attr(content), '
            'meta[name="price"]::attr(content), '
            '[data-price]::attr(data-price)'
        ).getall()
        for candidate in candidates:
            value = self.to_int(candidate)
            if value:
                return value

        # 3) Texto visible, pero SOLO antes de "Productos que te pueden interesar"
        visible_main = self.cut_main_product_text(body_text)

        patrones = [
            r"(?:al contado ahora|al contado|oferta)\s*gs\.?\s*([\d\.]+)",
            r"(?:precio(?:\s+especial)?|ahora)\s*[:\-]?\s*gs\.?\s*([\d\.]+)",
            r"gs\.?\s*([\d\.]+)",
        ]
        for patron in patrones:
            m = re.search(patron, visible_main, re.I)
            if m:
                value = self.to_int(m.group(1))
                if value:
                    return value

        return None

    def cut_main_product_text(self, text):
        text = self.clean_text(text)
        if not text:
            return ""

        lower = text.lower()
        cortes = [
            "productos que te pueden interesar",
            "información",
            "informacion",
            "métodos de pago",
            "metodos de pago",
            "también te puede interesar",
            "tambien te puede interesar",
            "productos relacionados",
        ]
        cut_at = len(text)
        for marker in cortes:
            pos = lower.find(marker)
            if pos != -1:
                cut_at = min(cut_at, pos)

        return text[:cut_at].strip()

    def extraer_categoria(self, response, nombre, categoria_origen="", marca=""):
        breadcrumbs = [
            self.clean_text(t)
            for t in response.css(
                '.breadcrumb *::text, [class*="breadcrumb"] *::text'
            ).getall()
            if self.clean_text(t)
        ]

        categoria_base = categoria_origen
        if not categoria_base:
            for crumb in breadcrumbs:
                if crumb and crumb.lower() not in {"inicio", "home", "productos", "producto"}:
                    categoria_base = crumb
                    break

        return extract_category(
            nombre=nombre,
            categoria_original=categoria_base,
            marca=marca,
        )

    def extraer_marca(self, response, nombre, body_text):
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            for brand in self._extract_brand_from_jsonld(data):
                brand = self.clean_text(brand)
                if brand:
                    return brand

        brand = self.clean_text(response.css("meta[property='product:brand']::attr(content)").get())
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

        if nombre:
            nombre_clean = self.clean_text(nombre)
            if text.lower().startswith(nombre_clean.lower()):
                text = text[len(nombre_clean):].strip(" -:|\n\t")

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

    def normalize_url(self, url):
        url = (url or "").strip()
        if not url:
            return ""
        url = url.replace("http://", "https://")
        url = url.split("#")[0].strip()
        if not url:
            return ""
        if not (
            url.startswith("https://www.tiendagonzalito.com.py")
            or url.startswith("https://tiendagonzalito.com.py")
        ):
            return ""
        return url.rstrip("/")
