import json
import re
import scrapy
from scraper.items import ProductoItem
from scraper.utils.brands import extract_brand


class InverfinProductosSpider(scrapy.Spider):
    name = "inverfin_productos"
    store_name = "Inverfin"
    allowed_domains = ["inverfin.com.py"]

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
        categoria_origen = (
            response.css("h1::text").get()
            or response.css("title::text").get(default="")
        ).strip()

        vistos = set()

        for href in response.css('a[href*="/products/"]::attr(href)').getall():
            href = response.urljoin(href.strip())

            if not href:
                continue

            # Normalizar URL para no visitar variantes
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
                text = " ".join(
                    t.strip() for t in a.css("::text").getall() if t.strip()
                ).lower()
                href = a.attrib.get("href", "").strip()
                if href and ("siguiente" in text or "next" in text):
                    next_page = href
                    break

        if next_page:
            yield response.follow(next_page, callback=self.parse)

    def parse_producto(self, response):
        item = ProductoItem()
        categoria_origen = response.meta.get("categoria_origen", "").strip()

        product_json = self.extract_product_json(response)

        if product_json:
            nombre = (product_json.get("title") or "").strip()
            descripcion_html = product_json.get("description", "") or ""
            descripcion = self.clean_html_text(descripcion_html)

            categoria = (
                product_json.get("type")
                or categoria_origen
                or "Sin categoría"
            )

            marca = (product_json.get("vendor") or "").strip()

            variants = product_json.get("variants", []) or []
            first_variant = variants[0] if variants else {}

            precio = self.shopify_price_to_int(first_variant.get("price"))

            stock = "En stock"
            if variants:
                any_available = any(bool(v.get("available")) for v in variants)
                stock = "En stock" if any_available else "Sin stock"

            imagen = ""
            images = product_json.get("images", []) or []
            if images:
                imagen = images[0].strip()

            item["nombre"] = nombre
            item["precio"] = precio
            item["url"] = response.url.split("?")[0].rstrip("/")
            item["categoria"] = str(categoria).strip()
            item["tienda"] = self.store_name
            item["stock"] = stock
            item["imagen"] = imagen
            item["marca"] = marca
            item["descripcion"] = descripcion

            item = self.normalizar_item(item)

            if item["nombre"] and item["precio"] is not None:
                yield item
            return

        # Fallback HTML
        nombre = (
            response.css("h1::text").get()
            or response.css("title::text").get(default="")
        ).strip()

        body_text = " ".join(
            t.strip() for t in response.css("body ::text").getall() if t.strip()
        )

        precio = self.parse_precio(body_text)

        imagen = (
            response.css('meta[property="og:image"]::attr(content)').get()
            or response.css('img[src*="cdn"]::attr(src)').get()
            or response.css('img::attr(src)').get()
            or ""
        )
        if imagen:
            imagen = response.urljoin(imagen)

        stock_text = body_text.lower()
        if "sin stock" in stock_text or "agotado" in stock_text or "no disponible" in stock_text:
            stock = "Sin stock"
        else:
            stock = "En stock"

        descripcion = " ".join(
            t.strip()
            for t in response.css(
                ".product__description *::text, .rte *::text, .product-description *::text"
            ).getall()
            if t.strip()
        )[:1000]

        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url.split("?")[0].rstrip("/")
        item["categoria"] = categoria_origen or "Sin categoría"
        item["tienda"] = self.store_name
        item["stock"] = stock
        item["imagen"] = imagen
        item["marca"] = extract_brand(nombre)
        item["descripcion"] = descripcion

        item = self.normalizar_item(item)

        if item["nombre"] and item["precio"] is not None:
            yield item


    def normalizar_item(self, item):
        marca = (item.get("marca") or "").strip()
        if not marca or marca.lower() in {"sin marca", "no brand", "n/a", "na"}:
            item["marca"] = "Genérico"
        else:
            item["marca"] = marca

        categoria = (item.get("categoria") or "").strip()
        if not categoria or categoria.lower() in {"sin categoría", "sin categoria", "uncategorized"}:
            item["categoria"] = "Otros"
        else:
            item["categoria"] = categoria

        return item

    def extract_product_json(self, response):
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            raw = raw.strip()
            if not raw:
                continue

            try:
                data = json.loads(raw)
            except json.JSONDecodeError:
                continue

            if isinstance(data, dict) and data.get("@type") == "Product":
                offers = data.get("offers", {})
                brand = data.get("brand", {})
                image = data.get("image", [])

                return {
                    "title": data.get("name", ""),
                    "description": data.get("description", ""),
                    "vendor": brand.get("name", "") if isinstance(brand, dict) else "",
                    "type": "",
                    "images": image if isinstance(image, list) else ([image] if image else []),
                    "variants": [{
                        "price": offers.get("price") if isinstance(offers, dict) else None,
                        "available": (
                            offers.get("availability", "").endswith("InStock")
                            if isinstance(offers, dict) else True
                        ),
                    }],
                }

        scripts = response.css("script::text").getall()
        for raw in scripts:
            if '"variants"' not in raw or '"title"' not in raw:
                continue

            match = re.search(r'(\{"id":.*?"variants":\s*\[.*?\]\s*\})', raw, re.DOTALL)
            if not match:
                continue

            json_text = match.group(1)
            try:
                return json.loads(json_text)
            except json.JSONDecodeError:
                continue

        return None

    def clean_html_text(self, html):
        if not html:
            return ""
        text = re.sub(r"<[^>]+>", " ", html)
        text = re.sub(r"\s+", " ", text).strip()
        return text[:1000]

    def parse_precio(self, texto):
        if not texto:
            return None

        patrones = [
            r'Precio de venta\s*Gs\.\s*([\d\.]+)',
            r'Precio habitual\s*Gs\.\s*([\d\.]+)',
            r'Gs\.\s*([\d\.]+)',
        ]

        for patron in patrones:
            match = re.search(patron, texto, re.I)
            if match:
                try:
                    return int(match.group(1).replace(".", ""))
                except ValueError:
                    return None

        return None

    def shopify_price_to_int(self, value):
        if value is None or value == "":
            return None

        try:
            return int(float(str(value)))
        except (TypeError, ValueError):
            return None