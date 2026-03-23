import json
import re
import scrapy
from scraper.items import ProductoItem


class CHProductosSpider(scrapy.Spider):
    name = "ch_productos"
    store_name = "Comfort House"
    allowed_domains = ["ch.com.py"]
    start_urls = [
        "https://www.ch.com.py/climatizacion",
        "https://www.ch.com.py/tecnologia",
        "https://www.ch.com.py/electrodomesticos",
        "https://www.ch.com.py/muebles-y-accesorios",
        "https://www.ch.com.py/salud-y-belleza",
        "https://www.ch.com.py/deporte-y-aire-libre",
        "https://www.ch.com.py/herramientas-maquinas-y-equipos",
        "https://www.ch.com.py/bebes-y-juguetes",
        "https://www.ch.com.py/motos-y-accesorios",
    ]

    def parse(self, response):
        categoria_origen = response.css("h1::text").get(default="").strip()

        vistos = set()
        for href in response.css('a[href*="/catalogo/"]::attr(href)').getall():
            href = response.urljoin(href.strip())
            if not href or href in vistos:
                continue
            vistos.add(href)
            yield response.follow(
                href,
                callback=self.parse_producto,
                meta={"categoria_origen": categoria_origen}
            )

        next_page = None
        for a in response.css("a"):
            text = " ".join(t.strip() for t in a.css("::text").getall() if t.strip())
            href = a.attrib.get("href", "").strip()
            if "Página Siguiente" in text and href:
                next_page = href
                break

        if next_page:
            yield response.follow(next_page, callback=self.parse)

    def parse_producto(self, response):
        item = ProductoItem()

        categoria_origen = response.meta.get("categoria_origen", "").strip()
        data = self.extract_product_json(response)

        if data:
            producto = data.get("producto", {}) or {}
            variante = data.get("variante", {}) or {}

            nombre = (
                data.get("nombreCompleto")
                or producto.get("nombre")
                or response.css("h1::text").get(default="").strip()
            )

            categoria = producto.get("categoria") or categoria_origen or "Sin categoría"
            marca = producto.get("marca") or ""
            precio = data.get("precioMonto")
            stock = "En stock" if variante.get("tieneStock") else "Sin stock"

            imagen = None
            img_obj = variante.get("img", {})
            if isinstance(img_obj, dict):
                imagen = img_obj.get("u")

            if imagen and imagen.startswith("//"):
                imagen = "https:" + imagen
            elif imagen:
                imagen = response.urljoin(imagen)

            url = variante.get("url") or response.url

            item["nombre"] = (nombre or "").strip()
            item["precio"] = self.to_int(precio)
            item["url"] = url.strip()
            item["categoria"] = categoria.strip()
            item["tienda"] = self.store_name
            item["stock"] = stock
            item["imagen"] = imagen or ""

            # Campo opcional si más adelante querés guardarlo
            item["marca"] = marca.strip()

            if item["nombre"]:
                yield item
            return

        # Fallback si el JSON no aparece
        nombre = response.css("h1::text").get(default="").strip()
        precio_texto = " ".join(response.css("body ::text").getall())
        precio = self.parse_precio(precio_texto)

        categoria = categoria_origen or "Sin categoría"
        stock_texto = " ".join(response.css("body ::text").getall()).lower()
        stock = "Sin stock" if "sin stock" in stock_texto or "agotado" in stock_texto else "En stock"

        imagen = response.css('img[src*="/catalogo/"]::attr(src)').get()
        if imagen:
            imagen = response.urljoin(imagen)

        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url
        item["categoria"] = categoria
        item["tienda"] = "Comfort House"
        item["stock"] = stock
        item["imagen"] = imagen or ""

        if item["nombre"]:
            yield item

    def extract_product_json(self, response):
        scripts = response.css("script::text").getall()
        body_text = "\n".join(scripts + response.css("body ::text").getall())

        # Busca el objeto que contiene producto/variante/precioMonto
        match = re.search(
            r'(\{"sku":\{.*?"precioMonto":\d+.*?\})',
            body_text,
            re.DOTALL
        )
        if not match:
            return None

        raw = match.group(1)

        try:
            return json.loads(raw)
        except json.JSONDecodeError:
            return None

    def parse_precio(self, texto):
        if not texto:
            return None

        match = re.search(r'PYG\s*([\d\.]+)', texto)
        if not match:
            return None

        try:
            return int(match.group(1).replace(".", ""))
        except ValueError:
            return None

    def to_int(self, value):
        if value is None or value == "":
            return None
        try:
            return int(value)
        except (TypeError, ValueError):
            return None