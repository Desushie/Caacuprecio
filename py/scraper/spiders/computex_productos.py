import re
import scrapy
from scraper.items import ProductoItem


class ComputexProductosSpider(scrapy.Spider):
    name = "computex_productos"
    store_name = "Computex"
    allowed_domains = ["computex.com.py"]
    start_urls = ["https://computex.com.py/productos/"]

    def parse(self, response):
        product_links = response.css('a[href*="/productos/"]::attr(href)').getall()

        vistos = set()

        for href in product_links:
            href = href.strip()

            if not href:
                continue
            if href.endswith("/productos/"):
                continue
            if "/page/" in href:
                continue
            if href in vistos:
                continue

            vistos.add(href)
            yield response.follow(href, callback=self.parse_producto)

        # paginación
        for a in response.css("a"):
            text = " ".join(a.css("::text").getall()).strip()
            href = a.attrib.get("href", "").strip()
            if "Página Siguiente" in text and href:
                yield response.follow(href, callback=self.parse)
                break

    def parse_producto(self, response):
        item = ProductoItem()

        nombre = response.css("h1::text").get(default="").strip()

        precio_texto = " ".join(response.css("body ::text").getall())
        precio = self.parse_precio(precio_texto)

        categorias = response.css('a[href*="/categoria/"]::text').getall()
        categoria = categorias[0].strip() if categorias else "Sin categoría"

        # mejor selector de imagen
        imagen = (
            response.css('img[src*="uploads"]::attr(src)').get()
            or response.css('img::attr(src)').get()
        )

        if imagen:
            imagen = response.urljoin(imagen)

        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = ""
        item["imagen"] = imagen or ""
        item["marca"] = ""
        item["descripcion"] = ""

        if item["nombre"]:
            yield item

    def parse_precio(self, texto):
        if not texto:
            return None

        match = re.search(r'₲\s*([\d\.]+)', texto)
        if not match:
            return None

        try:
            return int(match.group(1).replace(".", ""))
        except ValueError:
            return None