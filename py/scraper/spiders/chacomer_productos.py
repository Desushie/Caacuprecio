import json
import re
import scrapy
from scraper.items import ProductoItem
from scraper.utils.brands import extract_brand
from scraper.utils.categories import extract_category


class ChacomerProductosSpider(scrapy.Spider):
    name = "chacomer_productos"
    store_name = "Chacomer"
    allowed_domains = ["chacomer.com.py", "www.chacomer.com.py"]
    start_urls = [
        "https://www.chacomer.com.py/hogar.html",
        "https://www.chacomer.com.py/deportes.html",
        "https://www.chacomer.com.py/auto.html",
        "https://www.chacomer.com.py/moto.html",
        "https://www.chacomer.com.py/indumentaria.html",
        "https://www.chacomer.com.py/maquinas.html",
        "https://www.chacomer.com.py/catalog/category/view/s/alimentos/id/766/",
    ]

    custom_settings = {
        "DOWNLOAD_DELAY": 0.25,
        "CONCURRENT_REQUESTS": 8,
    }

    def parse(self, response):
        categoria_origen = self.extraer_categoria_listado(response)
        vistos = set()

        product_selectors = [
            'a.product-item-link::attr(href)',
            '.product-item-info a.product-item-link::attr(href)',
            '.products-grid .product-item-link::attr(href)',
            'ol.products li.product-item a[href]::attr(href)',
        ]

        for sel in product_selectors:
            for href in response.css(sel).getall():
                href = self.normalizar_url_producto(response, href)
                if not href or href in vistos or self.es_link_no_producto(href):
                    continue
                vistos.add(href)
                yield response.follow(href, callback=self.parse_producto, meta={"categoria_origen": categoria_origen})

        next_page = self.extraer_next_page(response)
        if next_page:
            yield response.follow(next_page, callback=self.parse)

    def normalizar_url_producto(self, response, href):
        href = response.urljoin((href or "").strip())
        return href.split("?")[0].split("#")[0].rstrip("/")

    def es_link_no_producto(self, href):
        href_l = href.lower()
        categorias_principales = ["/hogar.html", "/deportes.html", "/auto.html", "/moto.html", "/indumentaria.html", "/maquinas.html"]
        if any(href_l.endswith(cat) for cat in categorias_principales):
            return True
        no_producto = ["/customer/", "/checkout/", "/wishlist/", "/contact", "/catalogsearch/", "/catalog/category/"]
        return any(x in href_l for x in no_producto)

    def parse_producto(self, response):
        nombre_raw = (
            response.css("h1 span.base::text").get()
            or response.css("h1::text").get()
            or response.css('meta[property="og:title"]::attr(content)').get()
            or ""
        )
        nombre = self.limpiar_texto(nombre_raw)
        
        # Limpieza de textos basura
        nombre = re.sub(r"SELECCIONA UNA MARCA", "", nombre, flags=re.I).strip()
        nombre = re.sub(r"\s*-\s*CHACOMER\s*$", "", nombre, flags=re.I).strip()

        # Evitar capturar páginas de categoría como productos
        if not nombre or nombre.upper() in ["MOTOS", "HOGAR", "DEPORTES", "AUTO", "MOTO", "INDUMENTARIA", "MAQUINAS"]:
            return

        body_text = self.limpiar_texto(" ".join(response.css("body ::text").getall()))
        
        # EXTRACCIÓN DE PRECIO: Captura el valor de oferta (el más bajo)
        precio_raw = self.extraer_precio(response)
        precio = precio_raw if precio_raw is not None else "Sobre consulta"

        # LÓGICA DE STOCK: Se guarda como 1 (Disponible) o 0 (Consultar)
        status_texto = self.detectar_status_stock(body_text)
        stock_value = 1 if status_texto == "En stock" else 0

        item = ProductoItem()
        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url
        item["categoria"] = self.extraer_categoria_producto(response, nombre)
        item["tienda"] = self.store_name
        item["stock"] = stock_value  # Ahora es 1 o 0 para evitar el KeyError
        item["imagen"] = self.extraer_imagen(response)
        item["marca"] = self.extraer_marca(response, nombre, body_text)
        item["descripcion"] = self.extraer_descripcion(response)
        yield item

    def extraer_next_page(self, response):
        for sel in ['link[rel="next"]::attr(href)', '.pages-item-next a::attr(href)', 'a.next::attr(href)']:
            href = response.css(sel).get()
            if href: return response.urljoin(href)
        return None

    def extraer_precio(self, response):
        # Capturamos todos los montos de precio disponibles en la página
        precios = response.css('[data-price-amount]::attr(data-price-amount)').getall()
        if precios:
            # Convertimos a números y nos quedamos con el menor (el precio de oferta)
            valores = [self._parse_num(p) for p in precios if self._parse_num(p)]
            if valores:
                return min(valores)
        
        # Fallback para precios en texto plano si Magento no cargó el data-attribute
        meta_price = response.css('meta[property="product:price:amount"]::attr(content)').get()
        return self._parse_num(meta_price)

    def extraer_imagen(self, response):
        # Soporte para bicicletas Scott (Fotorama script)
        scripts = response.css('script[type="text/x-magento-init"]::text').getall()
        for s in scripts:
            if 'mage/gallery/gallery' in s:
                try:
                    data = json.loads(s)
                    for key in data:
                        if 'gallery-placeholder' in key:
                            return data[key]['mage/gallery/gallery']['data'][0]['full']
                except: pass

        meta_img = response.css('meta[property="og:image"]::attr(content)').get()
        if meta_img: return response.urljoin(meta_img)

        candidatos = [
            response.css('.product.media img::attr(data-src)').get(),
            response.css('.fotorama__img::attr(src)').get(),
        ]
        for img in candidatos:
            if img and "placeholder" not in img.lower():
                return response.urljoin(img)
        return ""

    def extraer_descripcion(self, response):
        for sel in ['.product.attribute.description .value *::text', '#description *::text']:
            txt = self.limpiar_texto(" ".join(response.css(sel).getall()))
            if len(txt) > 20: return txt
        return ""

    def extraer_marca(self, response, nombre, body_text):
        marca_site = response.css(".product.attribute.brand .value::text").get()
        if self.es_marca_valida(marca_site): return self.limpiar_texto(marca_site)

        marca_tab = response.xpath("//th[contains(text(), 'Marca')]/following-sibling::td/text()").get()
        if self.es_marca_valida(marca_tab): return self.limpiar_texto(marca_tab)

        marca_det = extract_brand(nombre) or extract_brand(body_text)
        return marca_det if self.es_marca_valida(marca_det) else "Genérico"

    def es_marca_valida(self, marca):
        if not marca: return False
        m = self.limpiar_texto(marca).lower()
        if any(x in m for x in ["selecciona", "seleccioná", "chacomer", "marca"]):
            return False
        return len(m) > 1

    def extraer_categoria_listado(self, response):
        cat = self.limpiar_texto(response.css("h1::text").get() or response.css("title::text").get())
        return re.sub(r"\s*-\s*CHACOMER\s*$", "", cat, flags=re.I).strip()

    def extraer_categoria_producto(self, response, nombre):
        crumbs = [self.limpiar_texto(x) for x in response.css('.breadcrumbs li a span::text, .breadcrumbs li strong::text').getall()]
        utiles = [c for c in crumbs if c.lower() not in {"inicio", "home", "chacomer"}]
        cat = utiles[-2] if len(utiles) >= 2 else (utiles[-1] if utiles else "Otros")
        return extract_category(cat) or extract_category(nombre) or cat

    def detectar_status_stock(self, body_text):
        txt = body_text.lower()
        # Si el sitio dice "En stock" o "Disponible", es 1
        if "en stock" in txt or "disponible" in txt:
            return "En stock"
        # Si dice explícitamente agotado, es 0
        if any(x in txt for x in ["agotado", "sin stock", "out of stock"]):
            return "Consultar stock"
        return "En stock"

    def _parse_num(self, value):
        if not value: return None
        # Limpia Gs, puntos y comas para quedar solo con dígitos
        text = re.sub(r"[^\d]", "", str(value))
        try: return int(text)
        except: return None

    def limpiar_texto(self, texto):
        if not texto: return ""
        texto = re.sub(r"<[^>]+>", " ", str(texto))
        return re.sub(r"\s+", " ", texto).strip(" -\n\t\r")