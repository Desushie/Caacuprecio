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
        encontrados = 0

        # Primero: selectores específicos de productos del listado.
        product_selectors = [
            'a.product-item-link::attr(href)',
            '.product-item-info a.product-item-link::attr(href)',
            '.products-grid .product-item-link::attr(href)',
            '.product-item-photo::attr(href)',
            '.product.photo.product-item-photo::attr(href)',
            '[data-container="product-grid"] a[href]::attr(href)',
            'ol.products li.product-item a[href]::attr(href)',
        ]

        for sel in product_selectors:
            for href in response.css(sel).getall():
                href = self.normalizar_url_producto(response, href)
                if not href or href in vistos:
                    continue
                if self.es_link_no_producto(href):
                    continue
                vistos.add(href)
                encontrados += 1
                yield response.follow(
                    href,
                    callback=self.parse_producto,
                    meta={"categoria_origen": categoria_origen},
                    priority=10,
                )

        # Fallback: si no encontró productos con selectores específicos,
        # probar con cualquier link del listado que parezca de producto.
        if encontrados == 0:
            for href in response.css('a[href]::attr(href)').getall():
                href = self.normalizar_url_producto(response, href)
                if not href or href in vistos:
                    continue
                if self.es_link_no_producto(href):
                    continue
                if not self.parece_producto(href):
                    continue
                vistos.add(href)
                yield response.follow(
                    href,
                    callback=self.parse_producto,
                    meta={"categoria_origen": categoria_origen},
                    priority=10,
                )

        self.logger.info("Listado %s | productos detectados en página: %s", response.url, encontrados)

        next_page = self.extraer_next_page(response)
        if next_page:
            yield response.follow(next_page, callback=self.parse, priority=-10)

    def normalizar_url_producto(self, response, href):
        href = response.urljoin((href or "").strip())
        if not href:
            return ""
        href = href.split("#")[0]
        return href

    def parece_producto(self, href):
        href_l = href.lower()
        return (
            "/catalog/product/" in href_l
            or "/product/" in href_l
            or href_l.endswith(".html")
        )

    def parse_producto(self, response):
        nombre = self.limpiar_texto(
            response.css("h1::text").get()
            or response.css('meta[property="og:title"]::attr(content)').get()
            or response.css("title::text").get(default="")
        )
        nombre = re.sub(r"\s*-\s*CHACOMER\s*$", "", nombre, flags=re.I).strip()

        if not nombre or nombre.lower() in {"hogar", "deportes", "auto", "moto", "indumentaria", "maquinas", "alimentos"}:
            return

        body_text = self.limpiar_texto(" ".join(
            t.strip() for t in response.css("body ::text").getall() if t.strip()
        ))

        precio = self.extraer_precio(response, body_text)
        if precio is None:
            self.logger.debug("Producto sin precio, omitido: %s", response.url)
            return

        descripcion = self.extraer_descripcion(response, body_text)
        imagen = self.extraer_imagen(response)
        marca = self.extraer_marca(response, nombre, descripcion)
        categoria = self.extraer_categoria_producto(response, nombre)
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

    def es_link_no_producto(self, href):
        href_l = href.lower()
        no_producto = [
            "/customer/",
            "/checkout/",
            "/wishlist/",
            "/contact",
            "/blog",
            "/faq",
            "/privacy",
            "/terms",
            "/sales/guest/",
            "/catalog/category/",
            "/catalogsearch/",
            "/search",
            "/amshopby/",
            "/post-venta",
            "/sucursales",
            "/devoluciones",
            "/politicas",
            "/envio",
            "/quienes-somos",
            "/la-empresa",
            "/trabaja-con-nosotros",
            "/newsletter",
            "?product_list_order=",
            "?product_list_dir=",
            "?product_list_mode=",
        ]
        if any(x in href_l for x in no_producto):
            return True
        if re.search(r"/(hogar|deportes|auto|moto|indumentaria|maquinas)\.html(?:\?.*)?$", href_l):
            return True
        return False

    def extraer_next_page(self, response):
        # Selectores específicos del paginador de Magento.
        for sel in [
            'link[rel="next"]::attr(href)',
            '.pages-item-next a::attr(href)',
            'a.next::attr(href)',
            'li.item.pages-item-next a::attr(href)',
            'a[title="Siguiente"]::attr(href)',
            'a[title="Next"]::attr(href)',
            'a[aria-label="Next"]::attr(href)',
            'a[aria-label="Siguiente"]::attr(href)',
        ]:
            href = response.css(sel).get()
            if href:
                return response.urljoin(href)

        # Fallback por texto.
        for a in response.css("a"):
            texto = self.limpiar_texto(" ".join(a.css("::text").getall())).lower()
            title = self.limpiar_texto(a.attrib.get("title", "")).lower()
            aria = self.limpiar_texto(a.attrib.get("aria-label", "")).lower()
            href = (a.attrib.get("href") or "").strip()
            if not href:
                continue

            if any(v in title for v in ["siguiente", "next"]) or any(v in aria for v in ["siguiente", "next"]):
                return response.urljoin(href)

            if texto in {"página siguiente", "pagina siguiente", "siguiente", ">", "next"}:
                return response.urljoin(href)

        return None

    def extraer_precio(self, response, body_text):
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            raw = (raw or "").strip()
            if not raw:
                continue
            try:
                data = json.loads(raw)
            except Exception:
                continue
            precio = self._buscar_precio_jsonld(data)
            if precio is not None:
                return precio

        for sel in [
            'meta[property="product:price:amount"]::attr(content)',
            'meta[itemprop="price"]::attr(content)',
            '[itemprop="price"]::attr(content)',
            '[data-price-amount]::attr(data-price-amount)',
            '.price-wrapper::attr(data-price-amount)',
            '.special-price .price::text',
            '.old-price .price::text',
            '.price-final_price .price::text',
            '.product-info-price .price::text',
            '.price-box .price::text',
            '.normal-price .price::text',
        ]:
            for val in response.css(sel).getall():
                parsed = self._parse_num(val)
                if parsed is not None:
                    return parsed

        patrones = [
            r"precio\s+especial\s*pyg\s*([\d\.]+)",
            r"precio\s+especial\s*₲\s*([\d\.]+)",
            r"precio\s+especial\s*gs\.?\s*([\d\.]+)",
            r"precio\s+al\s+contado\s*[:\-]?\s*(?:pyg|₲|gs\.?)\s*([\d\.]+)",
            r"al\s+contado\s*[:\-]?\s*(?:pyg|₲|gs\.?)\s*([\d\.]+)",
            r"\bpyg\s*([\d\.]+)",
            r"₲\s*([\d\.]+)",
            r"gs\.?\s*([\d\.]+)",
        ]
        texto = body_text.lower()
        encontrados = []
        for patron in patrones:
            for m in re.finditer(patron, texto, re.I):
                parsed = self._parse_num(m.group(1))
                if parsed is not None:
                    encontrados.append(parsed)
            if encontrados and "precio especial" in patron:
                return max(encontrados)

        if encontrados:
            return max(encontrados)

        return None

    def _buscar_precio_jsonld(self, data):
        if isinstance(data, dict):
            offers = data.get("offers")
            precio = self._extraer_precio_offers(offers)
            if precio is not None:
                return precio
            for v in data.values():
                precio = self._buscar_precio_jsonld(v)
                if precio is not None:
                    return precio
        elif isinstance(data, list):
            for item in data:
                precio = self._buscar_precio_jsonld(item)
                if precio is not None:
                    return precio
        return None

    def _extraer_precio_offers(self, offers):
        if isinstance(offers, dict):
            for key in ["price", "lowPrice", "highPrice"]:
                if key in offers:
                    parsed = self._parse_num(offers.get(key))
                    if parsed is not None:
                        return parsed
        elif isinstance(offers, list):
            vals = []
            for offer in offers:
                parsed = self._extraer_precio_offers(offer)
                if parsed is not None:
                    vals.append(parsed)
            if vals:
                return max(vals)
        return None

    def _parse_num(self, value):
        if value is None:
            return None
        text = str(value).strip()
        if not text:
            return None
        text = re.sub(r"[^\d,\.]", "", text)
        if not text:
            return None
        if "," in text and "." in text:
            text = text.replace(".", "").replace(",", ".")
        elif text.count(".") > 1:
            text = text.replace(".", "")
        try:
            return int(round(float(text)))
        except Exception:
            return None

    def extraer_imagen(self, response):
        candidatos = []

        for sel in [
            'meta[property="og:image"]::attr(content)',
            'meta[name="twitter:image"]::attr(content)',
            '[itemprop="image"]::attr(src)',
            '.product.media img::attr(src)',
            '.product.media img::attr(data-src)',
            '.product.media img::attr(data-large_image)',
            '.gallery-placeholder img::attr(src)',
            '.fotorama__active img::attr(src)',
        ]:
            candidatos.extend(response.css(sel).getall())

        for raw in candidatos:
            if not raw:
                continue

            if "," in raw and " " in raw:
                raw = raw.split(",")[0].split(" ")[0].strip()

            url = response.urljoin(raw.strip())
            if not url or url.startswith("data:image"):
                continue
            if "placeholder" in url.lower():
                continue
            if any(ext in url.lower() for ext in [".jpg", ".jpeg", ".png", ".webp", ".avif"]):
                return url

        return ""

    def extraer_descripcion(self, response, body_text):
        bloques = []

        for sel in [
            '.product.attribute.description .value *::text',
            '.product.attribute.overview .value *::text',
            '.product.data.items .item.content *::text',
            '.product.attribute.details .value *::text',
            '.product.attribute.additional .value *::text',
        ]:
            txt = self.limpiar_texto(" ".join(response.css(sel).getall()))
            if txt and len(txt) > 30:
                bloques.append(txt)

        descripcion = max(bloques, key=len) if bloques else ""

        if not descripcion:
            m = re.search(
                r"especificaciones principales\s*(.*?)\s*(?:medios de pago online|detalles|más información|suscríbete al newsletter|estamos para ayudarte)",
                body_text,
                re.I,
            )
            if m:
                descripcion = self.limpiar_texto(m.group(1))

        if not descripcion:
            m = re.search(
                r"detalles\s*(.*?)\s*(?:más información|suscríbete al newsletter|estamos para ayudarte)",
                body_text,
                re.I,
            )
            if m:
                descripcion = self.limpiar_texto(m.group(1))

        descripcion = re.sub(r"#html-body.*", " ", descripcion, flags=re.I)
        descripcion = re.sub(r"\[data-pb-style.*?\]", " ", descripcion, flags=re.I)
        descripcion = re.sub(r"\b(js|css)\b.*", " ", descripcion, flags=re.I)
        descripcion = re.sub(
            r"(medios de pago online|suscríbete|newsletter|estamos para ayudarte).*",
            " ",
            descripcion,
            flags=re.I,
        )
        descripcion = re.sub(r"\b(ver más|cancelar|comprar|favoritos|comparar)\b", " ", descripcion, flags=re.I)
        descripcion = self.limpiar_texto(descripcion)
        return descripcion

    def extraer_marca(self, response, nombre, descripcion):
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            brand = self._buscar_brand_jsonld(data)
            if brand:
                return brand

        marca = self.limpiar_texto(
            response.xpath("//*[contains(translate(normalize-space(.), 'MARCA', 'marca'), 'marca')]/following::*[1]/text()").get()
            or response.css(".product.attribute.brand .value::text").get()
            or response.css('meta[property="product:brand"]::attr(content)').get()
            or ""
        )

        marca = self.limpiar_texto(marca)
        if self.es_marca_valida(marca):
            return marca

        for texto in [nombre, descripcion]:
            marca = extract_brand(texto)
            if self.es_marca_valida(marca):
                return marca

        marca = extract_brand(nombre)
        if self.es_marca_valida(marca):
            return marca

        return "Genérico"

    def _buscar_brand_jsonld(self, data):
        if isinstance(data, dict):
            brand = data.get("brand")
            if isinstance(brand, dict):
                name = brand.get("name")
                if self.es_marca_valida(name):
                    return self.limpiar_texto(name)
            elif self.es_marca_valida(brand):
                return self.limpiar_texto(brand)
            for v in data.values():
                found = self._buscar_brand_jsonld(v)
                if found:
                    return found
        elif isinstance(data, list):
            for item in data:
                found = self._buscar_brand_jsonld(item)
                if found:
                    return found
        return ""

    def es_marca_valida(self, marca):
        if not marca:
            return False
        marca = self.limpiar_texto(marca)
        invalidas = {
            "marca", "ver más", "comprar", "favoritos", "comparar", "en stock",
            "chacomer", "precio especial", "pyg", "productos", "producto", "genérico"
        }
        return bool(marca) and marca.lower() not in invalidas and len(marca) <= 60

    def extraer_categoria_listado(self, response):
        categoria = self.limpiar_texto(
            response.css("h1::text").get()
            or response.css("title::text").get(default="")
        )
        categoria = re.sub(r"\s*-\s*CHACOMER\s*$", "", categoria, flags=re.I).strip()
        return categoria or "Otros"

    def extraer_categoria_producto(self, response, nombre):
        crumbs = [
            self.limpiar_texto(x)
            for x in response.css('.breadcrumbs li a span::text, .breadcrumbs li strong::text').getall()
            if self.limpiar_texto(x)
        ]

        ignorar = {"inicio", "home", "chacomer"}
        utiles = [c for c in crumbs if c.lower() not in ignorar]

        if len(utiles) >= 2:
            categoria = utiles[-2]
        elif utiles:
            categoria = utiles[-1]
        else:
            categoria = extract_category(nombre) or response.meta.get("categoria_origen")

        categoria = self.limpiar_texto(categoria)
        categoria = extract_category(categoria) or extract_category(nombre) or categoria

        if not categoria or categoria.lower() in {"productos", "sin categoría", "uncategorized"}:
            categoria = extract_category(nombre) or extract_category(response.meta.get("categoria_origen") or "") or response.meta.get("categoria_origen") or "Otros"

        return categoria

    def extraer_stock(self, body_text):
        txt = (body_text or "").lower()
        if "agotado" in txt or "sin stock" in txt or "out of stock" in txt:
            return "Sin stock"
        if "en stock" in txt or "disponible" in txt:
            return "En stock"
        return "Consultar stock"

    def limpiar_texto(self, texto):
        if not texto:
            return ""
        texto = re.sub(r"<[^>]+>", " ", str(texto))
        texto = texto.replace("\xa0", " ")
        texto = re.sub(r"\s+", " ", texto)
        return texto.strip(" -\n\t\r")
