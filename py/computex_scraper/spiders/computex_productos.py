import re
import scrapy
from computex_scraper.items import ProductoItem


class ComputexProductosSpider(scrapy.Spider):
    name = "computex_productos"
    allowed_domains = ["computex.com.py"]
    start_urls = ["https://computex.com.py/productos/"]

    def parse(self, response):
        # Computex usa /productos/slug/
        product_links = response.css('a[href*="/productos/"]::attr(href)').getall()

        vistos = set()

        for href in product_links:
            href = href.strip()

            # Evitar links que no sean productos reales
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

        # Paginación
        next_page = None
        for a in response.css("a"):
            text = " ".join(a.css("::text").getall()).strip()
            href = a.attrib.get("href", "").strip()
            if "Página Siguiente" in text and href:
                next_page = href
                break

        if next_page:
            yield response.follow(next_page, callback=self.parse)

    def parse_producto(self, response):
        item = ProductoItem()

        nombre = response.css("h1::text").get(default="").strip()

        # En el detalle se ve como: Precio: ₲ 1.200.000
        precio_texto = " ".join(response.css("body ::text").getall())
        precio = self.parse_precio(precio_texto)

        categorias = response.css('a[href*="/categoria/"]::text').getall()
        categoria = categorias[0].strip() if categorias else ""

        imagen = response.css("img::attr(src)").get()

        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url
        item["categoria"] = categoria or "Sin categoría"
        item["tienda"] = "Computex"
        item["stock"] = ""
        item["imagen"] = response.urljoin(imagen) if imagen else ""

        if item["nombre"]:
            yield item

    def parse_precio(self, texto):
        if not texto:
            return None

        # Busca valores tipo ₲ 1.200.000
        match = re.search(r'₲\s*([\d\.]+)', texto)
        if not match:
            return None

        valor = match.group(1).replace(".", "")
        try:
            return int(valor)
        except ValueError:
            return None