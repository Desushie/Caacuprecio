import json
import re
import scrapy

from scraper.items import ProductoItem
from scraper.utils.brands import extract_brand
from scraper.utils.categories import extract_category


class InverfinProductosSpider(scrapy.Spider):
    name = "inverfin_productos"
    store_name = "Inverfin"
    allowed_domains = ["inverfin.com.py", "www.inverfin.com.py"]

    custom_settings = {
        "DOWNLOAD_DELAY": 6,
        "RANDOMIZE_DOWNLOAD_DELAY": True,
        "CONCURRENT_REQUESTS": 1,
        "CONCURRENT_REQUESTS_PER_DOMAIN": 1,
        "AUTOTHROTTLE_ENABLED": True,
        "AUTOTHROTTLE_START_DELAY": 3,
        "AUTOTHROTTLE_MAX_DELAY": 20,
        "AUTOTHROTTLE_TARGET_CONCURRENCY": 1.0,
        "RETRY_TIMES": 8,
        "COOKIES_ENABLED": False,
        "DEFAULT_REQUEST_HEADERS": {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
            "Accept-Language": "es-ES,es;q=0.9,en;q=0.8",
            "Cache-Control": "no-cache",
            "Pragma": "no-cache",
        },
    }

    start_urls = [
        "https://inverfin.com.py/collections/motos",
        "https://inverfin.com.py/collections/tv-y-audio",
        "https://inverfin.com.py/collections/salud-y-belleza",
        "https://inverfin.com.py/collections/deportes",
        "https://inverfin.com.py/collections/climatizacion",
        "https://inverfin.com.py/collections/smartphones",
        "https://inverfin.com.py/collections/tecnologia",
        "https://inverfin.com.py/collections/electrodomesticos",
    ]

    def parse(self, response):
        categoria_origen = self.clean_text(
            response.css("h1::text").get()
            or response.css("title::text").get(default="")
        )

        vistos = set()

        for href in response.css('a[href*="/products/"]::attr(href)').getall():
            href = response.urljoin((href or "").strip())
            if not href:
                continue

            href = href.split("?")[0].rstrip("/")
            if href in vistos:
                continue

            vistos.add(href)
            yield response.follow(
                href,
                callback=self.parse_producto,
                meta={"categoria_origen": categoria_origen},
            )

        next_page = (
            response.css('link[rel="next"]::attr(href)').get()
            or response.css('a[rel="next"]::attr(href)').get()
            or response.css('a.next::attr(href)').get()
        )

        if not next_page:
            for a in response.css("a"):
                text = self.clean_text(" ".join(a.css("::text").getall())).lower()
                href = (a.attrib.get("href") or "").strip()
                if href and ("siguiente" in text or "next" in text):
                    next_page = href
                    break

        if next_page:
            yield response.follow(next_page, callback=self.parse)

    def parse_producto(self, response):
        categoria_origen = response.meta.get("categoria_origen", "").strip()
        jsonld = self.extract_jsonld_product(response)
        shopify = self.extract_shopify_product(response)

        body_text = self.clean_text(" ".join(
            t.strip() for t in response.css("body ::text").getall() if t.strip()
        ))

        nombre = self.clean_text(
            (jsonld or {}).get("title")
            or (shopify or {}).get("title")
            or response.css("h1::text").get()
            or response.css("title::text").get(default="")
        )
        if not nombre:
            return

        precio = self.extract_visible_price(response)
        if precio is None:
            precio = self.extract_json_price(jsonld, shopify)
        if precio is None:
            precio = self.parse_precio_fallback(body_text)

        descripcion = self.extract_description(response, jsonld, shopify, nombre)
        imagen = self.extract_image(response, jsonld, shopify)
        marca = self.extract_brand_value(nombre, body_text, jsonld, shopify)

        categoria = (
            extract_category((shopify or {}).get("type") or "")
            or extract_category(categoria_origen)
            or extract_category(nombre)
            or categoria_origen
            or "Otros"
        )

        stock = self.extract_stock(response, body_text, shopify, jsonld)

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

        item = self.normalizar_item(item)

        if item["nombre"] and item["precio"] is not None:
            yield item

    # ---------- precio ----------
    def extract_visible_price(self, response):
        ordered_selectors = [
            ".product__info-container .price__sale .price-item--sale::text",
            ".product__info-container .price__sale .price-item--regular::text",
            ".product__info-container .price__regular .price-item--regular::text",
            ".product__info-container .price-item--sale::text",
            ".product__info-container .price-item--regular::text",
            ".product__info-container span.money::text",
            ".product__info-container [class*='price']::text",
            "[data-product-price]::attr(data-product-price)",
            "[data-product-price]::text",
        ]

        for sel in ordered_selectors:
            for raw in response.css(sel).getall():
                value = self.price_from_text(raw)
                if value is not None and value >= 1000:
                    return value

        meta_candidates = response.css(
            "meta[property='product:price:amount']::attr(content), "
            "meta[property='og:price:amount']::attr(content), "
            "meta[itemprop='price']::attr(content)"
        ).getall()
        for raw in meta_candidates:
            value = self.price_from_number_string(raw)
            if value is not None and value >= 1000:
                return value

        return None

    def extract_json_price(self, jsonld, shopify):
        if jsonld:
            offers = jsonld.get("offers_data")
            if isinstance(offers, dict):
                price = self.price_from_number_string(offers.get("price"))
                if price is not None:
                    return price
            elif isinstance(offers, list):
                for offer in offers:
                    if not isinstance(offer, dict):
                        continue
                    price = self.price_from_number_string(offer.get("price"))
                    if price is not None:
                        return price

        if shopify:
            variants = shopify.get("variants") or []

            for variant in variants:
                if bool(variant.get("available")):
                    price = self.price_from_number_string(variant.get("price"))
                    if price is not None:
                        return price

            for variant in variants:
                price = self.price_from_number_string(variant.get("price"))
                if price is not None:
                    return price

        return None

    def parse_precio_fallback(self, texto):
        texto = self.cut_main_text(texto)
        if not texto:
            return None

        patrones = [
            r"Precio de venta\s*Gs\.\s*([\d\.]+)",
            r"Precio habitual\s*Gs\.\s*([\d\.]+)",
            r"Oferta\s*Gs\.\s*([\d\.]+)",
            r"Gs\.\s*([\d\.]+)",
        ]

        for patron in patrones:
            m = re.search(patron, texto, re.I)
            if m:
                return self.to_int(m.group(1))

        return None

    # ---------- descripción ----------
    def extract_description(self, response, jsonld, shopify, nombre):
        # 1) JSON-LD
        if jsonld and jsonld.get("description"):
            cleaned = self.clean_description(jsonld.get("description"), nombre)
            if self.is_good_description(cleaned):
                return cleaned[:1500]

        # 2) Shopify description HTML
        if shopify and shopify.get("description"):
            cleaned = self.clean_description(self.clean_html_text(shopify.get("description")), nombre)
            if self.is_good_description(cleaned):
                return cleaned[:1500]

        # 3) DOM solo de contenedores reales de descripción.
        # Antes tomaba div::text y agarraba header tipo "Saltar al contenido..."
        strict_selectors = [
            ".product__description p::text",
            ".product__description li::text",
            ".product__description br::text",
            ".rte p::text",
            ".rte li::text",
            ".product-description p::text",
            ".product-description li::text",
            "[class*='description'] p::text",
            "[class*='description'] li::text",
        ]

        for sel in strict_selectors:
            parts = [self.clean_text(t) for t in response.css(sel).getall() if self.clean_text(t)]
            if parts:
                joined = " ".join(parts)
                cleaned = self.clean_description(joined, nombre)
                if self.is_good_description(cleaned):
                    return cleaned[:1500]

        # 4) fallback muy controlado: buscar cerca de "Descripción"
        page_texts = [self.clean_text(t) for t in response.css("body ::text").getall() if self.clean_text(t)]
        for i, txt in enumerate(page_texts):
            if txt.lower() in {"descripción", "descripcion"}:
                chunk = page_texts[i + 1:i + 20]
                joined = " ".join(chunk)
                cleaned = self.clean_description(joined, nombre)
                if self.is_good_description(cleaned):
                    return cleaned[:1500]
                break

        return ""

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

    # ---------- imagen / marca / stock ----------
    def extract_image(self, response, jsonld, shopify):
        candidates = []

        if jsonld:
            img = jsonld.get("image")
            if isinstance(img, list):
                candidates.extend(img)
            elif isinstance(img, str):
                candidates.append(img)

        if shopify:
            candidates.extend(shopify.get("images") or [])

        candidates.extend(response.css(
            "meta[property='og:image']::attr(content), "
            "meta[name='twitter:image']::attr(content), "
            "img::attr(src), img::attr(data-src), img::attr(data-lazy-src)"
        ).getall())

        for img in candidates:
            img = (img or "").strip()
            if not img or img.startswith("data:image"):
                continue
            if img.startswith("//"):
                img = "https:" + img
            return response.urljoin(img)

        return ""

    def extract_brand_value(self, nombre, body_text, jsonld, shopify):
        if jsonld:
            brand = jsonld.get("brand")
            if isinstance(brand, dict):
                brand = brand.get("name")
            if isinstance(brand, str) and self.clean_text(brand):
                return self.clean_text(brand)

        if shopify:
            brand = self.clean_text(shopify.get("vendor"))
            if brand:
                return brand

        brand = self.clean_text(extract_brand(nombre))
        if brand:
            return brand

        brand = self.clean_text(extract_brand(body_text[:300]))
        if brand:
            return brand

        return "Genérico"

    def extract_stock(self, response, body_text, shopify, jsonld):
        if shopify:
            variants = shopify.get("variants") or []
            if variants:
                return "En stock" if any(bool(v.get("available")) for v in variants) else "Sin stock"

        if jsonld:
            offers = jsonld.get("offers_data") or {}
            if isinstance(offers, dict):
                av = str(offers.get("availability", "")).lower()
                if av:
                    return "En stock" if "instock" in av else "Sin stock"
            elif isinstance(offers, list):
                vals = [str(x.get("availability", "")).lower() for x in offers if isinstance(x, dict)]
                if vals:
                    return "En stock" if any("instock" in x for x in vals) else "Sin stock"

        low = body_text.lower()
        if any(x in low for x in ["sin stock", "agotado", "no disponible"]):
            return "Sin stock"
        return "En stock"

    # ---------- JSON ----------
    def extract_jsonld_product(self, response):
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            raw = (raw or "").strip()
            if not raw:
                continue
            try:
                data = json.loads(raw)
            except Exception:
                continue

            candidates = data if isinstance(data, list) else [data]
            for entry in candidates:
                if not isinstance(entry, dict) or entry.get("@type") != "Product":
                    continue
                return {
                    "title": entry.get("name", ""),
                    "description": entry.get("description", ""),
                    "brand": entry.get("brand"),
                    "image": entry.get("image"),
                    "offers_data": entry.get("offers"),
                }
        return None

    def extract_shopify_product(self, response):
        scripts = response.css('script[type="application/json"]::text, script::text').getall()

        for raw in scripts:
            if '"variants"' not in raw or '"title"' not in raw:
                continue

            for match in re.finditer(r'(\{.*?"variants"\s*:\s*\[.*?\].*?\})', raw, re.DOTALL):
                block = match.group(1)
                try:
                    data = json.loads(block)
                except Exception:
                    continue
                if isinstance(data, dict) and data.get("title"):
                    return data

        return None

    # ---------- utilidades ----------
    def normalizar_item(self, item):
        marca = self.clean_text(item.get("marca") or "")
        if not marca or marca.lower() in {"sin marca", "no brand", "n/a", "na"}:
            marca_detectada = extract_brand(item.get("nombre") or "")
            item["marca"] = self.clean_text(marca_detectada) if marca_detectada else "Genérico"
        else:
            item["marca"] = marca

        categoria = self.clean_text(item.get("categoria") or "")
        if not categoria or categoria.lower() in {"sin categoría", "sin categoria", "uncategorized", "productos"}:
            item["categoria"] = extract_category(item.get("nombre") or "") or "Otros"
        else:
            item["categoria"] = categoria
        return item

    def clean_html_text(self, html):
        if not html:
            return ""
        text = re.sub(r"<[^>]+>", " ", str(html))
        text = text.replace("\xa0", " ")
        text = re.sub(r"\s+", " ", text).strip()
        return text[:1500]

    def cut_main_text(self, texto):
        texto = texto or ""
        lower = texto.lower()
        cortes = [
            "productos más buscados",
            "productos mas buscados",
            "palabras clave más buscadas",
            "palabras clave mas buscadas",
            "lo mas vendido",
            "lo más vendido",
            "también te puede interesar",
            "tambien te puede interesar",
            "productos relacionados",
        ]
        cut_at = len(texto)
        for marker in cortes:
            pos = lower.find(marker)
            if pos != -1:
                cut_at = min(cut_at, pos)
        return texto[:cut_at].strip()

    def price_from_text(self, raw):
        if not raw:
            return None
        raw = self.clean_text(raw)
        if "cuota" in raw.lower():
            return None

        m = re.search(r"Gs\.\s*([\d\.]+)", raw, re.I)
        if not m:
            return None
        return self.to_int(m.group(1))

    def price_from_number_string(self, value):
        if value is None or value == "":
            return None

        text = str(value).strip().replace(",", ".")
        try:
            val = float(text)
        except Exception:
            return None

        if val >= 10000000 and val % 100 == 0:
            val = val / 100

        return int(round(val))

    def to_int(self, value):
        if value is None:
            return None
        text = str(value).strip()
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
