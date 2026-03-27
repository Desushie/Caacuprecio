import json
import re
import scrapy
from scraper.items import ProductoItem
from scraper.utils.brands import extract_brand
from scraper.utils.categories import extract_category


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

        nombre = self.clean_text(
            response.css("h1::text").get()
            or response.css(".product-name::text").get()
            or response.css(".title::text").get(default="")
        )

        body_text = " ".join(
            t.strip() for t in response.css("body ::text").getall() if t.strip()
        )
        body_text = self.clean_text(body_text)

        precio = self.parse_precio(body_text)

        categoria = extract_category(categoria_origen) or extract_category(nombre) or categoria_origen or "Sin categoría"

        stock_text = body_text.lower()
        if any(x in stock_text for x in ["sin stock", "agotado", "no disponible"]):
            stock = "Sin stock"
        else:
            stock = "En stock"

        imagen = (
            response.css('meta[property="og:image"]::attr(content)').get()
            or response.css('meta[name="twitter:image"]::attr(content)').get()
            or response.css('img[src*="product"]::attr(src)').get()
            or response.css('img[src*="uploads"]::attr(src)').get()
            or response.css('img::attr(src)').get()
        )
        if imagen:
            imagen = response.urljoin(imagen)

        descripcion = self.extraer_descripcion(response, nombre)

        marca = self.extraer_marca(response, nombre, descripcion)

        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = stock
        item["imagen"] = imagen or ""
        item["marca"] = marca or "Genérico"
        item["descripcion"] = descripcion

        if item["nombre"]:
            yield item

    def parse_precio(self, texto):
        if not texto:
            return None

        match = re.search(r'PYG\s*([\d\.]+)', texto, re.I)
        if not match:
            return None

        try:
            return int(match.group(1).replace('.', ''))
        except ValueError:
            return None

    def extraer_descripcion(self, response, nombre=""):
        candidatos = []

        # 1) Selectores comunes de descripción del producto
        selectores = [
            ".description *::text",
            ".product-description *::text",
            ".tab-description *::text",
            ".product.attribute.description *::text",
            ".product-info-description *::text",
            "[itemprop='description'] *::text",
            "#description *::text",
            "#tab-description *::text",
            ".tabs .description *::text",
            ".tab-content *::text",
            ".product.data.items *::text",
        ]

        for selector in selectores:
            textos = response.css(selector).getall()
            texto = self.normalizar_bloque_texto(textos, nombre)
            if self.descripcion_valida(texto, nombre):
                candidatos.append(texto)

        # 2) Meta description / OpenGraph
        meta_desc = self.clean_text(
            response.css('meta[name="description"]::attr(content)').get()
            or response.css('meta[property="og:description"]::attr(content)').get()
            or ""
        )
        if self.descripcion_valida(meta_desc, nombre):
            candidatos.append(meta_desc)

        # 3) JSON-LD Product description
        for raw_json in response.css('script[type="application/ld+json"]::text').getall():
            for bloque in self.extraer_jsonld(raw_json):
                texto = self.clean_text(
                    bloque.get("description")
                    or bloque.get("disambiguatingDescription")
                    or ""
                )
                if self.descripcion_valida(texto, nombre):
                    candidatos.append(texto)

        # 4) Fallback: buscar secciones con títulos descriptivos visibles
        bloques_xpath = [
            "//*[contains(translate(normalize-space(.), 'DESCRIPCIONCARACTERISTICASDETALLES', 'descripcioncaracteristicasdetalles'), 'descripcion')]/following-sibling::*[1]//text()",
            "//*[contains(translate(normalize-space(.), 'DESCRIPCIONCARACTERISTICASDETALLES', 'descripcioncaracteristicasdetalles'), 'caracteristicas')]/following-sibling::*[1]//text()",
            "//*[contains(translate(normalize-space(.), 'DESCRIPCIONCARACTERISTICASDETALLES', 'descripcioncaracteristicasdetalles'), 'detalles')]/following-sibling::*[1]//text()",
        ]
        for xp in bloques_xpath:
            texto = self.normalizar_bloque_texto(response.xpath(xp).getall(), nombre)
            if self.descripcion_valida(texto, nombre):
                candidatos.append(texto)

        if not candidatos:
            return ""

        # Elegir el candidato más útil: priorizar más contenido real
        candidatos = sorted(set(candidatos), key=len, reverse=True)
        return self.limpiar_descripcion_final(candidatos[0], nombre)[:2000]


    def limpiar_descripcion_final(self, texto, nombre=""):
        texto = self.clean_text(texto)
        if not texto:
            return ""

        # Cortar antes de bloques de footer/redes/soporte muy típicos
        cortes = [
            r'\bFacebook\b',
            r'\bTwitter\b',
            r'\bYoutube\b',
            r'\bInstagram\b',
            r'\bWhatsapp\b',
            r'\bTalleres autorizados\b',
            r'\bGarant[ií]a motocicletas\b',
            r'\bHorario de atenci[oó]n\b',
            r'\bVenta telef[oó]nica\b',
            r'\bServicios a empresas\b',
            r'\bPost venta\b',
            r'\bCobranzas\b',
            r'\bEmpresa\b',
            r'\bNuestra Empresa\b',
            r'\bContacto\b',
            r'\bSucursales\b',
            r'\bPagar mi\b',
            r'https?://\S+',
        ]
        for patron in cortes:
            texto = re.split(patron, texto, maxsplit=1, flags=re.I)[0]

        # Quitar códigos repetidos pegados, por ejemplo BS3462221BS3462221
        texto = re.sub(r'\b([A-Z]{1,4}\d{3,})(?:\1)+\b', '', texto)
        # Quitar dos códigos alfanuméricos largos consecutivos
        texto = re.sub(r'\b[A-Z]{1,4}\d{3,}\s*[A-Z]{1,4}\d{3,}\b', '', texto)
        # Quitar URLs sueltas y caracteres privados/iconos
        texto = re.sub(r'https?://\S+', '', texto)
        texto = re.sub(r'[\ue000-\uf8ff]+', ' ', texto)

        # Si el nombre se repite al inicio, remover una ocurrencia
        if nombre:
            texto = re.sub(r'^' + re.escape(nombre) + r'\s*', '', texto, flags=re.I)

        # Cortar textos de recomendación
        texto = re.split(r'\bProductos? que te pueden interesar\b', texto, maxsplit=1, flags=re.I)[0]

        texto = self.clean_text(texto)
        return texto

    def extraer_jsonld(self, raw_json):
        bloques = []
        if not raw_json:
            return bloques

        try:
            data = json.loads(raw_json)
        except Exception:
            return bloques

        def recolectar(obj):
            if isinstance(obj, dict):
                bloques.append(obj)
                for value in obj.values():
                    recolectar(value)
            elif isinstance(obj, list):
                for item in obj:
                    recolectar(item)

        recolectar(data)
        return bloques

    def normalizar_bloque_texto(self, textos, nombre=""):
        texto = " ".join(t.strip() for t in textos if t and t.strip())
        texto = self.clean_text(texto)

        # Limpieza de basura frecuente
        texto = re.sub(r'\bDescripción\b\s*:?','', texto, flags=re.I)
        texto = re.sub(r'\bCaracterísticas\b\s*:?','', texto, flags=re.I)
        texto = re.sub(r'\bDetalles\b\s*:?','', texto, flags=re.I)
        texto = re.sub(r'\bCompartir\b.*$', '', texto, flags=re.I)
        texto = re.sub(r'\bMétodos? de pago\b.*$', '', texto, flags=re.I)
        texto = re.sub(r'\bCuotas?\b.*$', '', texto, flags=re.I)
        texto = re.sub(r'\bPYG\s*[\d\.]+.*$', '', texto, flags=re.I)

        if nombre:
            texto = re.sub(re.escape(nombre), '', texto, flags=re.I)

        texto = self.clean_text(texto)
        return texto

    def descripcion_valida(self, texto, nombre=""):
        if not texto:
            return False
        if len(texto) < 25:
            return False
        if nombre and texto.strip().lower() == nombre.strip().lower():
            return False

        basura = [
            "agregar al carrito",
            "comprar ahora",
            "medios de pago",
            "consultar",
            "whatsapp",
            "iniciar sesión",
        ]
        bajo = texto.lower()
        if any(x in bajo for x in basura) and len(texto) < 80:
            return False
        return True

    def clean_text(self, value):
        if not value:
            return ""
        value = re.sub(r'\s+', ' ', value)
        return value.strip()

    def extraer_marca(self, response, nombre="", descripcion=""):
        # 1) JSON-LD suele ser la fuente más confiable
        for raw_json in response.css('script[type="application/ld+json"]::text').getall():
            for bloque in self.extraer_jsonld(raw_json):
                brand = bloque.get("brand")
                if isinstance(brand, dict):
                    brand = brand.get("name") or brand.get("@id") or ""
                elif isinstance(brand, list):
                    vals = []
                    for b in brand:
                        if isinstance(b, dict):
                            vals.append(b.get("name") or "")
                        elif isinstance(b, str):
                            vals.append(b)
                    brand = next((v for v in vals if v), "")
                if isinstance(brand, str):
                    brand = self.limpiar_marca(brand)
                    if self.marca_valida(brand, nombre):
                        return brand

        # 2) Campos visibles / meta tags específicos
        candidatos = [
            response.css('[itemprop="brand"]::text').get(),
            response.css('meta[property="product:brand"]::attr(content)').get(),
            response.css('meta[name="brand"]::attr(content)').get(),
            response.css('.brand::text').get(),
            response.css('.product-brand::text').get(),
            response.xpath("//*[contains(translate(normalize-space(.), 'MARCA', 'marca'), 'marca')]/following::*[1]//text()").get(),
        ]
        for cand in candidatos:
            brand = self.limpiar_marca(cand or "")
            if self.marca_valida(brand, nombre):
                return brand

        # 3) Usar utilitario sobre fuentes acotadas, nunca sobre todo el body
        for fuente in (nombre, descripcion):
            brand = self.limpiar_marca(extract_brand(fuente or ""))
            if self.marca_valida(brand, nombre):
                return brand

        # 4) Heurística por comienzo del nombre
        brand = self.inferir_marca_desde_nombre(nombre)
        if self.marca_valida(brand, nombre):
            return brand

        return "Genérico"

    def limpiar_marca(self, marca):
        marca = self.clean_text(marca)
        if not marca:
            return ""
        marca = re.sub(r'https?://\S+', '', marca, flags=re.I)
        marca = re.sub(r'^[\-:|/]+|[\-:|/]+$', '', marca).strip()
        if len(marca.split()) > 3:
            return ""
        return marca

    def marca_valida(self, marca, nombre=""):
        if not marca:
            return False
        inval = {
            'facebook', 'twitter', 'youtube', 'instagram', 'whatsapp',
            'bristol', 'catalogo', 'producto', 'productos', 'paraguay',
            'en stock', 'sin stock', 'consultar stock', 'genérico'
        }
        bajo = marca.strip().lower()
        if bajo in inval:
            return False
        if re.search(r'^(bs)?\d{4,}$', bajo, re.I):
            return False
        if len(bajo) <= 1:
            return False
        if nombre and bajo == nombre.strip().lower():
            return False
        return True

    def inferir_marca_desde_nombre(self, nombre):
        if not nombre:
            return ""

        nombre = self.clean_text(nombre)
        marcas_conocidas = [
            'BaBylissPRO', 'Philco', 'Samsung', 'LG', 'Sony', 'TCL', 'Xiaomi',
            'Apple', 'JBL', 'Philips', 'Whirlpool', 'Midea', 'Electrolux',
            'Lenovo', 'HP', 'Acer', 'Asus', 'Oppo', 'Honor', 'Daewoo',
            'Carrier', 'Comfee', 'Pioneer', 'Nike', 'Puma', 'Adidas',
            'Consumer', 'Mallory', 'Ariete', 'Buler', 'Tokyo', 'Aiwa',
            'Motorola', 'Nokia', 'Huawei', 'Master-G', 'Master G'
        ]
        for marca in sorted(marcas_conocidas, key=len, reverse=True):
            if re.search(rf'^\s*{re.escape(marca)}\b', nombre, re.I):
                return marca

        primera = nombre.split()[0]
        primera = re.sub(r'[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9+.-]', '', primera)
        stop = {
            'tv', 'smart', 'combo', 'kit', 'aire', 'estufa', 'camiseta',
            'heladera', 'lavarropas', 'notebook', 'celular', 'horno', 'microondas'
        }
        if primera and primera.lower() not in stop and not re.match(r'^\d+$', primera):
            return primera
        return ""

