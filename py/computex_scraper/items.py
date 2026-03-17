import scrapy

class ProductoItem(scrapy.Item):
    nombre = scrapy.Field()
    precio = scrapy.Field()
    url = scrapy.Field()
    categoria = scrapy.Field()
    tienda = scrapy.Field()
    stock = scrapy.Field()
    imagen = scrapy.Field()