import json
import re
import scrapy
from scraper.items import ProductoItem
from scraper.utils.brands import extract_brand
from scraper.utils.categories import extract_category


class ComputexProductosSpider(scrapy.Spider):
    name = "computex_productos"
    store_name = "Computex"
    allowed_domains = ["computex.com.py"]
    start_urls = ["https://computex.com.py/productos/"]

    def parse(self, response):
        vistos = set()

        for href in response.css('a[href*="/productos/"]::attr(href)').getall():
            href = response.urljoin((href or "").strip())
            if not href:
                continue

            href_l = href.lower()
            if href.rstrip("/") == "https://computex.com.py/productos":
                continue
            if "/page/" in href_l:
                continue
            if any(x in href_l for x in ["?product_category=", "?orderby=", "?min_price=", "?max_price="]):
                continue

            href = href.split("?")[0].rstrip("/")
            if href in vistos:
                continue

            vistos.add(href)
            yield response.follow(href, callback=self.parse_producto)

        next_page = None
        for a in response.css("a"):
            text = " ".join(t.strip() for t in a.css("::text").getall() if t.strip())
            href = (a.attrib.get("href") or "").strip()
            if "Página Siguiente" in text and href:
                next_page = href
                break

        if next_page:
            yield response.follow(next_page, callback=self.parse)

    def parse_producto(self, response):
        nombre = self.limpiar_texto(
            response.css("h1::text").get()
            or response.css(".product_title::text").get()
            or response.css(".entry-title::text").get(default="")
        )

        if not nombre or nombre.lower() == "productos":
            return

        body_text = " ".join(t.strip() for t in response.css("body ::text").getall() if t.strip())
        precio = self.parse_precio(body_text)
        if precio is None:
            return

        imagen = self.extraer_imagen(response)
        marca = extract_brand(nombre)
        categoria_raw = self.extraer_categoria(response, nombre)
        categoria = extract_category(categoria_raw) or extract_category(nombre) or categoria_raw
        descripcion = self.extraer_descripcion(response)

        item = ProductoItem()
        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url.split("?")[0].rstrip("/")
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = "Consultar stock"
        item["imagen"] = imagen
        item["marca"] = marca
        item["descripcion"] = descripcion

        item = self.normalizar_item(item)

        yield item


    def normalizar_item(self, item):
        marca = self.limpiar_texto(item.get("marca") or "")
        if not marca or marca.lower() in {"sin marca", "no brand", "n/a", "na"}:
            item["marca"] = "Genérico"
        else:
            item["marca"] = marca

        categoria = self.limpiar_texto(item.get("categoria") or "")
        if not categoria or categoria.lower() in {"sin categoría", "sin categoria", "uncategorized"}:
            categoria = self.limpiar_texto(extract_category(item.get("nombre") or "")) or "Otros"
        item["categoria"] = categoria
        return item

    def limpiar_texto(self, texto):
        if not texto:
            return ""
        texto = re.sub(r"\s+", " ", texto)
        return texto.strip(" -\n\t\r")

    def extraer_categoria(self, response, nombre):
        candidatos = []

        # 1. Breadcrumbs / navegación
        breadcrumbs = [
            self.limpiar_texto(t)
            for t in response.css(
                '.woocommerce-breadcrumb a::text, '
                '.breadcrumb a::text, '
                'nav.woocommerce-breadcrumb a::text, '
                '[class*="breadcrumb"] a::text'
            ).getall()
            if self.limpiar_texto(t)
        ]
        breadcrumbs = [
            c for c in breadcrumbs
            if c.lower() not in {"inicio", "home", "productos", "tienda", "shop"}
        ]
        candidatos.extend(breadcrumbs)

        # 2. Categorías de WooCommerce / taxonomías visibles
        taxonomias = [
            self.limpiar_texto(t)
            for t in response.css(
                '.posted_in a::text, '
                '.product_meta .posted_in a::text, '
                'a[rel="tag"]::text, '
                '.product-categories a::text'
            ).getall()
            if self.limpiar_texto(t)
        ]
        candidatos.extend(taxonomias)

        # 3. Meta keywords / itemprops
        meta_keywords = response.css('meta[name="keywords"]::attr(content)').get() or ""
        for part in re.split(r"[,|]", meta_keywords):
            part = self.limpiar_texto(part)
            if part:
                candidatos.append(part)

        # 4. JSON-LD
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            for cat in self._buscar_categorias_jsonld(data):
                cat = self.limpiar_texto(cat)
                if cat:
                    candidatos.append(cat)

        # 5. Utilidad existente por nombre
        cat_nombre = self.limpiar_texto(extract_category(nombre))
        if cat_nombre:
            candidatos.append(cat_nombre)

        # Elegir la primera útil, evitando genéricas
        ignorar = {
            "productos", "producto", "tienda", "shop", "inicio", "home",
            "sin categoria", "uncategorized"
        }
        for cat in candidatos:
            cat_l = cat.lower()
            if cat_l in ignorar:
                continue
            if len(cat) < 3:
                continue
            return cat

        return "Otros"

    def _buscar_categorias_jsonld(self, data):
        encontrados = []
        if isinstance(data, dict):
            for k, v in data.items():
                if k.lower() in {"category", "articlesection"}:
                    if isinstance(v, str):
                        encontrados.append(v)
                    elif isinstance(v, list):
                        encontrados.extend([x for x in v if isinstance(x, str)])
                else:
                    encontrados.extend(self._buscar_categorias_jsonld(v))
        elif isinstance(data, list):
            for item in data:
                encontrados.extend(self._buscar_categorias_jsonld(item))
        return encontrados

    def extraer_imagen(self, response):
        candidatos = []

        # 1. Metas principales
        for sel in [
            'meta[property="og:image"]::attr(content)',
            'meta[name="twitter:image"]::attr(content)',
            'meta[itemprop="image"]::attr(content)',
        ]:
            val = response.css(sel).get()
            if val:
                candidatos.append(val)

        # 2. Imágenes del producto y lazy-load
        for sel in [
            '.woocommerce-product-gallery__image a::attr(href)',
            '.woocommerce-product-gallery__image img::attr(src)',
            '.woocommerce-product-gallery__image img::attr(data-src)',
            '.woocommerce-product-gallery__image img::attr(data-large_image)',
            '.product img::attr(src)',
            '.product img::attr(data-src)',
            '.product img::attr(data-lazy-src)',
            '.product img::attr(data-large_image)',
            'img.wp-post-image::attr(src)',
            'img.wp-post-image::attr(data-src)',
            'img::attr(src)',
            'img::attr(data-src)',
            'img::attr(data-lazy-src)',
            'img::attr(data-original)',
        ]:
            candidatos.extend(response.css(sel).getall())

        # 3. Srcset
        for srcset in response.css('img::attr(srcset)').getall():
            partes = [p.strip() for p in srcset.split(',') if p.strip()]
            for parte in partes:
                url = parte.split(' ')[0].strip()
                if url:
                    candidatos.append(url)

        # 4. JSON-LD image
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            candidatos.extend(self._buscar_imagenes_jsonld(data))

        for img in candidatos:
            img = (img or "").strip()
            if not img or img.startswith("data:image"):
                continue
            img_l = img.lower()
            if any(x in img_l for x in ["logo", "icon", "favicon", "placeholder", "loader", "banner"]):
                continue
            if img.startswith("//"):
                img = f"https:{img}"
            return response.urljoin(img)

        return ""

    def _buscar_imagenes_jsonld(self, data):
        encontrados = []
        if isinstance(data, dict):
            for k, v in data.items():
                if k.lower() == "image":
                    if isinstance(v, str):
                        encontrados.append(v)
                    elif isinstance(v, list):
                        encontrados.extend([x for x in v if isinstance(x, str)])
                    elif isinstance(v, dict):
                        url = v.get("url")
                        if isinstance(url, str):
                            encontrados.append(url)
                else:
                    encontrados.extend(self._buscar_imagenes_jsonld(v))
        elif isinstance(data, list):
            for item in data:
                encontrados.extend(self._buscar_imagenes_jsonld(item))
        return encontrados

    def extraer_descripcion(self, response):
        descripcion = " ".join(
            t.strip()
            for t in response.css(
                ".woocommerce-Tabs-panel--description *::text, "
                "#tab-description *::text, "
                ".product .woocommerce-product-details__short-description *::text"
            ).getall()
            if t.strip()
        )
        descripcion = self.limpiar_texto(descripcion)
        if descripcion:
            return descripcion[:1000]

        textos = response.css("body ::text").getall()
        textos = [self.limpiar_texto(t) for t in textos if self.limpiar_texto(t)]

        inicio = None
        fin = None
        for i, t in enumerate(textos):
            t_lower = t.lower()
            if t_lower in {"descripción", "descripcion"}:
                inicio = i + 1
                continue
            if inicio is not None and (
                "también te puede gustar" in t_lower
                or "tambien te puede gustar" in t_lower
                or "volver a la lista" in t_lower
                or "valoraciones" in t_lower
                or "reseñas" in t_lower
            ):
                fin = i
                break

        if inicio is not None:
            bloque = textos[inicio:fin] if fin is not None else textos[inicio:inicio + 30]
            bloque = [t for t in bloque if len(t) > 1]
            return self.limpiar_texto(" ".join(bloque))[:1000]

        return ""

    def parse_precio(self, texto):
        if not texto:
            return None

        match = re.search(r'₲\s*([\d\.]+)', texto)
        if not match:
            return None

        try:
            return int(match.group(1).replace('.', ''))
        except ValueError:
            return None
