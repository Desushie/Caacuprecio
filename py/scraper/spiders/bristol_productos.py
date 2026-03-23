import re
import scrapy
from scraper.items import ProductoItem


class BristolProductosSpider(scrapy.Spider):
    name = "bristol_productos"
    store_name = "Bristol"
    allowed_domains = ["bristol.com.py"]
    start_urls = [
        "https://www.bristol.com.py/televisores-y-audio",
        "https://www.bristol.com.py/electrodomesticos",
        "https://www.bristol.com.py/climatizacion",
        "https://www.bristol.com.py/celulares-y-smartwatches",
        "https://www.bristol.com.py/tecnologia",
        "https://www.bristol.com.py/hogar",
        "https://www.bristol.com.py/deportes-y-aire-libre",
        "https://www.bristol.com.py/motocicletas",
        "https://www.bristol.com.py/infantiles",
        "https://www.bristol.com.py/cuidado-personal",
    ]

    def parse(self, response):
        categoria_origen = response.css("h1::text").get(default="").strip()
        vistos = set()

        # Bristol usa detalles /catalogo/...
        for href in response.css('a[href*="/catalogo/"]::attr(href)').getall():
            href = response.urljoin(href.strip())

            if not href or href in vistos:
                continue

            vistos.add(href)
            yield response.follow(
                href,
                callback=self.parse_producto,
                meta={"categoria_origen": categoria_origen},
            )

        # Paginación: probar variantes comunes
        next_page = (
            response.css('a.next::attr(href)').get()
            or response.css('a[rel="next"]::attr(href)').get()
            or response.css('a.page-next::attr(href)').get()
        )

        if not next_page:
            for a in response.css("a"):
                text = " ".join(t.strip() for t in a.css("::text").getall() if t.strip()).lower()
                href = a.attrib.get("href", "").strip()
                if href and ("siguiente" in text or "next" in text):
                    next_page = href
                    break

        if next_page:
            yield response.follow(next_page, callback=self.parse)

    def parse_producto(self, response):
        item = ProductoItem()

        categoria_origen = response.meta.get("categoria_origen", "").strip()

        nombre = (
            response.css("h1::text").get()
            or response.css(".product-name::text").get()
            or response.css(".title::text").get(default="")
        ).strip()

        body_text = " ".join(t.strip() for t in response.css("body ::text").getall() if t.strip())

        precio = self.parse_precio(body_text)
        precio_anterior = self.parse_precio_anterior(body_text)

        # Categoría y marca: mejor esfuerzo
        categoria = categoria_origen or "Sin categoría"
        marca = self.extraer_marca(body_text)

        # Stock: en Bristol suele aparecer “Consultar” y cuotas, así que usamos heurística simple
        stock_text = body_text.lower()
        if "sin stock" in stock_text or "agotado" in stock_text or "no disponible" in stock_text:
            stock = "Sin stock"
        else:
            stock = "En stock"

        # Imagen principal: probar varias opciones
        imagen = (
            response.css('meta[property="og:image"]::attr(content)').get()
            or response.css('img[src*="product"]::attr(src)').get()
            or response.css('img[src*="uploads"]::attr(src)').get()
            or response.css('img::attr(src)').get()
        )
        if imagen:
            imagen = response.urljoin(imagen)

        # Descripción: mejor esfuerzo
        descripcion = " ".join(
            t.strip()
            for t in response.css(
                ".description *::text, .product-description *::text, .tab-description *::text"
            ).getall()
            if t.strip()
        )
        descripcion = descripcion[:1000]

        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = stock
        item["imagen"] = imagen or ""
        item['marca'] = item['nombre'].split()[0]
        item["descripcion"] = descripcion

        if item["nombre"]:
            yield item

    def parse_precio(self, texto):
        if not texto:
            return None

        # Bristol muestra precios tipo PYG 1.799.000
        match = re.search(r'PYG\s*([\d\.]+)', texto, re.I)
        if not match:
            return None

        try:
            return int(match.group(1).replace(".", ""))
        except ValueError:
            return None

    def parse_precio_anterior(self, texto):
        if not texto:
            return None

        # Busca formato tachado tipo ~~PYG 2.198.182~~ en texto parseado
        matches = re.findall(r'PYG\s*([\d\.]+)', texto, re.I)
        if len(matches) >= 2:
            try:
                return int(matches[1].replace(".", ""))
            except ValueError:
                return None
        return None

    def extraer_marca(self, texto):
        if not texto:
            return ""

        # Heurística simple: primera palabra reconocible al inicio del nombre/metadata
        marcas_comunes = [
            "Samsung", "LG", "Sony", "TCL", "Xiaomi", "Apple", "JBL", "Philips",
            "Whirlpool", "Midea", "Electrolux", "Lenovo", "HP", "Acer", "Asus",
            "Oppo", "Honor", "Daewoo", "Carrier", "Comfee", "Pioneer"
        ]

        for marca in marcas_comunes:
            if re.search(rf'\b{re.escape(marca)}\b', texto, re.I):
                return marca

        return ""