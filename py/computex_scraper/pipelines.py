import pymysql
from itemadapter import ItemAdapter


class MySQLPipeline:
    def open_spider(self, spider=None):
        self.connection = pymysql.connect(
            host="localhost",
            user="root",
            password="",
            database="Caacuprecio",
            charset="utf8mb4",
            autocommit=True,
            cursorclass=pymysql.cursors.Cursor
        )
        self.cursor = self.connection.cursor()

    def close_spider(self, spider=None):
        self.cursor.close()
        self.connection.close()

    def process_item(self, item, spider=None):
        adapter = ItemAdapter(item)

        nombre = (adapter.get("nombre") or "").strip()
        precio = adapter.get("precio")
        url = (adapter.get("url") or "").strip()
        categoria = (adapter.get("categoria") or "Sin categoría").strip()
        tienda = (adapter.get("tienda") or "Computex").strip()
        stock_texto = (adapter.get("stock") or "").strip()
        imagen = (adapter.get("imagen") or "").strip()

        if not nombre or not url:
            return item

        en_stock = self.parse_stock(stock_texto)
        imagen = self.clean_image(imagen)

        categoria_id = self.get_or_create_categoria(categoria)
        tienda_id = self.get_or_create_tienda(tienda)

        # Buscar producto existente por URL
        self.cursor.execute("""
            SELECT idproductos, pro_precio, pro_en_stock
            FROM productos
            WHERE pro_url = %s
            LIMIT 1
        """, (url,))
        row = self.cursor.fetchone()

        if row:
            producto_id, precio_anterior_db, stock_anterior_db = row

            # Actualizar producto principal
            self.cursor.execute("""
                UPDATE productos
                SET pro_nombre = %s,
                    pro_precio_anterior = %s,
                    pro_precio = %s,
                    pro_imagen = %s,
                    pro_en_stock = %s,
                    pro_fecha_scraping = NOW(),
                    pro_activo = 1,
                    tiendas_idtiendas = %s,
                    categorias_idcategorias = %s
                WHERE idproductos = %s
            """, (
                nombre,
                precio_anterior_db,
                precio if precio is not None else 0,
                imagen,
                en_stock,
                tienda_id,
                categoria_id,
                producto_id
            ))

            # Guardar historial si cambió algo importante
            if (
                (precio is not None and precio_anterior_db != precio)
                or stock_anterior_db != en_stock
            ):
                self.cursor.execute("""
                    INSERT INTO historial_precios (
                        productos_idproductos,
                        his_precio,
                        his_en_stock,
                        his_fecha
                    ) VALUES (%s, %s, %s, NOW())
                """, (
                    producto_id,
                    precio if precio is not None else 0,
                    en_stock
                ))
        else:
            # Insertar nuevo producto
            self.cursor.execute("""
                INSERT INTO productos (
                    pro_nombre,
                    pro_descripcion,
                    pro_precio,
                    pro_precio_anterior,
                    pro_imagen,
                    pro_url,
                    pro_en_stock,
                    pro_moneda,
                    pro_fecha_scraping,
                    pro_activo,
                    tiendas_idtiendas,
                    categorias_idcategorias
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, 'PYG', NOW(), 1, %s, %s)
            """, (
                nombre,
                "",
                precio if precio is not None else 0,
                None,
                imagen,
                url,
                en_stock,
                tienda_id,
                categoria_id
            ))

            producto_id = self.cursor.lastrowid

            self.cursor.execute("""
                INSERT INTO historial_precios (
                    productos_idproductos,
                    his_precio,
                    his_en_stock,
                    his_fecha
                ) VALUES (%s, %s, %s, NOW())
            """, (
                producto_id,
                precio if precio is not None else 0,
                en_stock
            ))

        # Mantener también productos_precios
        self.upsert_producto_precio(
            producto_id=producto_id,
            tienda_id=tienda_id,
            precio=precio,
            url=url,
            imagen=imagen,
            stock_texto=stock_texto
        )

        return item

    def get_or_create_categoria(self, nombre):
        self.cursor.execute("""
            SELECT idcategorias
            FROM categorias
            WHERE cat_nombre = %s
            LIMIT 1
        """, (nombre,))
        row = self.cursor.fetchone()

        if row:
            return row[0]

        self.cursor.execute("""
            INSERT INTO categorias (cat_nombre, cat_descripcion)
            VALUES (%s, NULL)
        """, (nombre,))
        return self.cursor.lastrowid

    def get_or_create_tienda(self, nombre):
        self.cursor.execute("""
            SELECT idtiendas
            FROM tiendas
            WHERE tie_nombre = %s
            LIMIT 1
        """, (nombre,))
        row = self.cursor.fetchone()

        if row:
            return row[0]

        self.cursor.execute("""
            INSERT INTO tiendas (
                tie_nombre,
                tie_descripcion,
                tie_logo,
                tie_ubicacion,
                tie_url
            ) VALUES (%s, NULL, NULL, NULL, NULL)
        """, (nombre,))
        return self.cursor.lastrowid

    def upsert_producto_precio(self, producto_id, tienda_id, precio, url, imagen, stock_texto):
        self.cursor.execute("""
            SELECT proprecio_id, precio
            FROM productos_precios
            WHERE productos_idproductos = %s
              AND tiendas_idtiendas = %s
              AND proprecio_url = %s
            LIMIT 1
        """, (producto_id, tienda_id, url))
        row = self.cursor.fetchone()

        if row:
            proprecio_id, precio_anterior_db = row
            self.cursor.execute("""
                UPDATE productos_precios
                SET precio = %s,
                    precio_anterior = %s,
                    proprecio_imagen = %s,
                    proprecio_stock = %s,
                    prop_estado = 'activo',
                    proprecio_fecha_actualizacion = NOW()
                WHERE proprecio_id = %s
            """, (
                precio if precio is not None else 0,
                precio_anterior_db,
                imagen,
                stock_texto or None,
                proprecio_id
            ))
        else:
            self.cursor.execute("""
                INSERT INTO productos_precios (
                    productos_idproductos,
                    tiendas_idtiendas,
                    precio,
                    precio_anterior,
                    proprecio_url,
                    proprecio_imagen,
                    proprecio_stock,
                    prop_estado,
                    proprecio_fecha_actualizacion
                ) VALUES (%s, %s, %s, NULL, %s, %s, %s, 'activo', NOW())
            """, (
                producto_id,
                tienda_id,
                precio if precio is not None else 0,
                url,
                imagen,
                stock_texto or None
            ))

    def parse_stock(self, texto):
        texto = (texto or "").strip().lower()

        if not texto:
            return 1

        if "agotado" in texto or "sin stock" in texto or "no disponible" in texto:
            return 0

        return 1

    def clean_image(self, imagen):
        if not imagen:
            return None

        imagen = imagen.strip()

        if imagen.startswith("data:image"):
            return None

        return imagen