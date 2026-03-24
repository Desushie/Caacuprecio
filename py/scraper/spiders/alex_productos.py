import json
import re
import scrapy
from scraper.items import ProductoItem
from scraper.utils.brands import extract_brand
from scraper.utils.categories import extract_category


class AlexProductosSpider(scrapy.Spider):
    name = "alex_productos"
    store_name = "Alex"
    allowed_domains = ["alex.com.py", "www.alex.com.py"]
    start_urls = [
        "https://www.alex.com.py/categoria/97/motocicletas?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/1/celulares-y-accesorios?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/7/televisores-y-equipos-de-audio?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/47/electrodomesticos?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/67/informatica?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/4/camas-y-colchones?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/9/cuidado-personal-y-salud?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/75/climatizacion?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/30/muebles?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/16/deportes?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/45/nautica?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/78/herramientas-y-jardin?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/92/linea-profesional?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/110/automotores?marcas=&categorias=&categorias_top=",
    ]

    custom_settings = {
        "DOWNLOAD_DELAY": 0.25,
    }

    def parse(self, response):
        categoria_origen = self.extraer_categoria_listado(response)
        vistos = set()

        # Links de productos
        for href in response.css('a[href*="/producto/"]::attr(href)').getall():
            href = response.urljoin((href or "").strip())
            href = href.split("?")[0].rstrip("/")
            if not href or href in vistos:
                continue
            vistos.add(href)
            yield response.follow(
                href,
                callback=self.parse_producto,
                meta={"categoria_origen": categoria_origen},
            )

        # Paginación: intentar por links con page / página / siguiente
        next_page = self.extraer_next_page(response)
        if next_page:
            yield response.follow(next_page, callback=self.parse)

    def parse_producto(self, response):
        body_text = self.limpiar_texto(" ".join(
            t.strip() for t in response.css("body ::text").getall() if t.strip()
        ))

        nombre = self.limpiar_texto(
            response.css("h1::text").get()
            or response.css("title::text").get(default="")
        )
        nombre = re.sub(r"\s*-\s*Alex\s*S\.A\.?$", "", nombre, flags=re.I).strip()

        if not nombre:
            return

        # Ignorar combos: el usuario pidió solo productos con precio al contado.
        # Regla: si parece combo o pack y no encontramos precio al contado, se descarta.
        if self.es_combo(nombre, body_text):
            precio_contado = self.extraer_precio_contado(response, body_text)
            if precio_contado is None:
                return
        else:
            precio_contado = self.extraer_precio_contado(response, body_text)
            if precio_contado is None:
                return

        imagen = self.extraer_imagen(response)
        descripcion = self.extraer_descripcion(response, body_text)
        categoria = self.extraer_categoria_producto(response, nombre)
        marca = self.extraer_marca(response, nombre, descripcion)
        stock = self.extraer_stock(body_text)

        item = ProductoItem()
        item["nombre"] = nombre
        item["precio"] = precio_contado
        item["url"] = response.url.split("?")[0].rstrip("/")
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = stock
        item["imagen"] = imagen
        item["marca"] = marca
        item["descripcion"] = descripcion
        yield item

    def limpiar_texto(self, texto):
        if not texto:
            return ""
        texto = re.sub(r"<[^>]+>", " ", texto)
        texto = re.sub(r"\s+", " ", texto)
        return texto.strip(" -\n\t\r")

    def es_combo(self, nombre, body_text):
        texto = f"{nombre} {body_text}".lower()
        patrones = [
            r"\bcombo\b",
            r"\bkit\b",
            r"\bpack\b",
            r"\bjuego\s+de\b",
            r"\bconjunto\b",
        ]
        return any(re.search(p, texto) for p in patrones)

    def extraer_precio_contado(self, response, body_text):
        # 1) JSON-LD / scripts
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            price = self._buscar_precio_jsonld(data)
            if price is not None:
                return price

        # 2) Metadatos / atributos visibles
        candidatos = []
        for sel in [
            '[data-price-cash]::attr(data-price-cash)',
            '[data-precio-contado]::attr(data-precio-contado)',
            '[data-price]::attr(data-price)',
            'meta[property="product:price:amount"]::attr(content)',
            'meta[itemprop="price"]::attr(content)',
        ]:
            val = response.css(sel).get()
            if val:
                parsed = self._parse_num(val)
                if parsed is not None:
                    candidatos.append(parsed)
        if candidatos:
            return max(candidatos)

        # 3) Texto explícito "al contado"
        patrones_contado = [
            r"al\s+contado\s*[:\-]?\s*Gs\.?\s*([\d\.]+)",
            r"o\s+compr[aá]\s+al\s+contado\s*[:\-]?\s*Gs\.?\s*([\d\.]+)",
            r"precio\s+al\s+contado\s*[:\-]?\s*Gs\.?\s*([\d\.]+)",
            r"contado\s*[:\-]?\s*Gs\.?\s*([\d\.]+)",
            r"al\s+contado\s*[:\-]?\s*₲\s*([\d\.]+)",
            r"o\s+compr[aá]\s+al\s+contado\s*[:\-]?\s*₲\s*([\d\.]+)",
        ]
        texto = body_text or ""
        for patron in patrones_contado:
            m = re.search(patron, texto, re.I)
            if m:
                value = self._parse_num(m.group(1))
                if value is not None:
                    return value

        # 4) Fallback: cuando el sitio muestra contado sin etiqueta tan clara pero cerca del producto
        # Si no hay ninguna mención a "contado", no usar cuotas.
        if not re.search(r"\bcontado\b", texto, re.I):
            return None

        nums = []
        for m in re.finditer(r"(?:Gs\.?|₲)\s*([\d\.]+)", texto, re.I):
            value = self._parse_num(m.group(1))
            if value is not None:
                nums.append(value)

        if not nums:
            return None

        # En estas páginas primero suelen venir cuotas chicas y luego el contado grande.
        return max(nums)

    def _buscar_precio_jsonld(self, data):
        if isinstance(data, dict):
            if str(data.get("@type", "")).lower() == "product":
                offers = data.get("offers")
                price = self._extraer_price_de_offers(offers)
                if price is not None:
                    return price
            for v in data.values():
                price = self._buscar_precio_jsonld(v)
                if price is not None:
                    return price
        elif isinstance(data, list):
            for item in data:
                price = self._buscar_precio_jsonld(item)
                if price is not None:
                    return price
        return None

    def _extraer_price_de_offers(self, offers):
        if isinstance(offers, dict):
            for key in ["price", "lowPrice", "highPrice"]:
                if key in offers:
                    parsed = self._parse_num(offers.get(key))
                    if parsed is not None:
                        return parsed
        elif isinstance(offers, list):
            vals = []
            for offer in offers:
                if isinstance(offer, dict):
                    parsed = self._extraer_price_de_offers(offer)
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
        m = re.search(r"([\d\.]+(?:,\d{1,2})?)", text)
        if not m:
            return None
        num = m.group(1).replace(".", "").replace(",", ".")
        try:
            return int(round(float(num)))
        except Exception:
            return None

    def extraer_imagen(self, response):
        candidatos = []
        for sel in [
            'meta[property="og:image"]::attr(content)',
            'meta[name="twitter:image"]::attr(content)',
            'meta[itemprop="image"]::attr(content)',
            '.product-image img::attr(src)',
            '.product-image img::attr(data-src)',
            '.product img::attr(src)',
            '.product img::attr(data-src)',
            'img::attr(src)',
            'img::attr(data-src)',
            'img::attr(data-lazy-src)',
        ]:
            for val in response.css(sel).getall():
                val = (val or "").strip()
                if not val or val.startswith("data:image"):
                    continue
                if any(x in val.lower() for x in [".jpg", ".jpeg", ".png", ".webp", "/uploads/", "/producto/"]):
                    candidatos.append(response.urljoin(val))
        return candidatos[0] if candidatos else ""

    def extraer_descripcion(self, response, body_text):
        bloques = []
        for sel in [
            '.descripcion *::text',
            '.description *::text',
            '.product-description *::text',
            '.detalle-producto *::text',
            '.detalle *::text',
            '.info-producto *::text',
            '.ficha-tecnica *::text',
            '[class*="description"] *::text',
            '[class*="detalle"] *::text',
            '[class*="ficha"] *::text',
        ]:
            textos = [self.limpiar_texto(t) for t in response.css(sel).getall() if self.limpiar_texto(t)]
            if textos:
                bloques.append(" ".join(textos))

        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            desc = self._buscar_desc_jsonld(data)
            if desc:
                bloques.append(desc)

        meta_desc = response.css('meta[name="description"]::attr(content), meta[property="og:description"]::attr(content)').get()
        if meta_desc:
            bloques.append(self.limpiar_texto(meta_desc))

        descripcion = ""
        for b in bloques:
            b = self.limpiar_descripcion(b)
            if len(b) > len(descripcion):
                descripcion = b

        if descripcion:
            return descripcion[:1500]

        # Fallback desde body_text si hay alguna sección útil
        texto = self.limpiar_descripcion(body_text)
        return texto[:800]

    def _buscar_desc_jsonld(self, data):
        if isinstance(data, dict):
            if "description" in data and isinstance(data["description"], str):
                return self.limpiar_texto(data["description"])
            for v in data.values():
                desc = self._buscar_desc_jsonld(v)
                if desc:
                    return desc
        elif isinstance(data, list):
            for item in data:
                desc = self._buscar_desc_jsonld(item)
                if desc:
                    return desc
        return ""

    def limpiar_descripcion(self, texto):
        if not texto:
            return ""
        texto = self.limpiar_texto(texto)
        cortes = [
            "relacionados",
            "suscribite a las novedades",
            "producto agregado",
            "seguir comparando",
            "comparar producto",
            "ir al carrito",
            "cantidad:",
            "total del carrito",
            "medios de pago",
            "contacto",
        ]
        lower = texto.lower()
        cut_pos = len(texto)
        for c in cortes:
            idx = lower.find(c)
            if idx != -1:
                cut_pos = min(cut_pos, idx)
        texto = texto[:cut_pos].strip()
        texto = re.sub(r'https?://\S+', ' ', texto)
        texto = re.sub(r'\s+', ' ', texto).strip()
        return texto

    def extraer_stock(self, body_text):
        txt = (body_text or "").lower()
        if any(x in txt for x in ["sin stock", "agotado", "no disponible"]):
            return "Sin stock"
        return "En stock"

    def extraer_marca(self, response, nombre, descripcion):
        # 1) JSON-LD / metas
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            marca = self._buscar_marca_jsonld(data)
            if marca:
                return marca

        for sel in [
            'meta[property="product:brand"]::attr(content)',
            'meta[name="brand"]::attr(content)',
            '[itemprop="brand"]::text',
            '[itemprop="brand"]::attr(content)',
            '.brand::text',
            '[class*="marca"]::text',
        ]:
            val = response.css(sel).get()
            val = self.limpiar_texto(val)
            if self.marca_valida(val):
                return val

        # 2) Utilidad existente
        marca = extract_brand(nombre) or extract_brand(descripcion)
        if self.marca_valida(marca):
            return marca

        # 3) Heurística simple por primeras palabras
        palabras = [p for p in re.split(r"\s+", nombre) if p]
        if palabras:
            candidata = palabras[0].strip("-_/.,")
            if self.marca_valida(candidata) and not candidata.isdigit():
                return candidata

        return "Genérico"

    def _buscar_marca_jsonld(self, data):
        if isinstance(data, dict):
            brand = data.get("brand")
            if isinstance(brand, dict):
                name = self.limpiar_texto(brand.get("name"))
                if self.marca_valida(name):
                    return name
            elif isinstance(brand, str) and self.marca_valida(brand):
                return self.limpiar_texto(brand)
            for v in data.values():
                found = self._buscar_marca_jsonld(v)
                if found:
                    return found
        elif isinstance(data, list):
            for item in data:
                found = self._buscar_marca_jsonld(item)
                if found:
                    return found
        return ""

    def marca_valida(self, marca):
        marca = self.limpiar_texto(marca)
        if not marca:
            return False
        inval = {"marca", "producto", "alex", "s.a", "sa", "inicio", "categoría", "categoria"}
        return marca.lower() not in inval and len(marca) >= 2

    def extraer_categoria_listado(self, response):
        candidatos = []
        candidatos.extend([
            self.limpiar_texto(t)
            for t in response.css('.breadcrumb a::text, [class*="breadcrumb"] a::text').getall()
            if self.limpiar_texto(t)
        ])
        candidatos.extend([
            self.limpiar_texto(t)
            for t in response.css('h1::text, title::text').getall()
            if self.limpiar_texto(t)
        ])
        ignorar = {"inicio", "home", "alex s.a.", "alex s.a"}
        for cat in candidatos:
            cat_l = cat.lower()
            if cat_l in ignorar:
                continue
            if "|" in cat:
                cat = self.limpiar_texto(cat.split("|")[0])
            if len(cat) >= 3:
                return cat
        return "Otros"

    def extraer_categoria_producto(self, response, nombre):
        categoria_origen = self.limpiar_texto(response.meta.get("categoria_origen", ""))
        candidatos = []

        # Breadcrumbs, el último antes del nombre suele ser la categoría/subcategoría.
        breadcrumbs = [
            self.limpiar_texto(t)
            for t in response.css('.breadcrumb a::text, [class*="breadcrumb"] a::text').getall()
            if self.limpiar_texto(t)
        ]
        candidatos.extend(breadcrumbs)

        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            candidatos.extend(self._buscar_categorias_jsonld(data))

        if categoria_origen:
            candidatos.append(categoria_origen)

        cat_nombre = self.limpiar_texto(extract_category(nombre))
        if cat_nombre:
            candidatos.append(cat_nombre)

        ignorar = {
            "inicio", "home", "alex", "alex s.a.", "alex s.a", "producto",
            "productos", "tienda", "shop", "sin categoría", "sin categoria", "uncategorized"
        }
        for cat in candidatos:
            cat = self.limpiar_texto(cat)
            if not cat:
                continue
            if "|" in cat:
                cat = self.limpiar_texto(cat.split("|")[0])
            if cat.lower() in ignorar:
                continue
            if cat.lower() == nombre.lower():
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

    def extraer_next_page(self, response):
        # Selectores comunes
        for sel in [
            'a[rel="next"]::attr(href)',
            'a.next::attr(href)',
            '.pagination a.next::attr(href)',
            '.page-next::attr(href)',
        ]:
            href = response.css(sel).get()
            if href:
                return href

        # Por texto del enlace
        for a in response.css("a"):
            text = self.limpiar_texto(" ".join(a.css("::text").getall())).lower()
            href = (a.attrib.get("href") or "").strip()
            if not href:
                continue
            if any(x in text for x in ["siguiente", "next", "próxima", "proxima"]):
                return href

        # Fallback por query/page/número siguiente
        current = response.url.split("#")[0]
        m = re.search(r"([?&]page=)(\d+)", current, re.I)
        if m:
            nxt = int(m.group(2)) + 1
            return re.sub(r"([?&]page=)\d+", rf"\g<1>{nxt}", current)

        if "?" in current:
            return current + "&page=2"
        return current + "?page=2"
