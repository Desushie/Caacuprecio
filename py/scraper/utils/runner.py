import sys

from scrapy.crawler import CrawlerProcess
from scrapy.utils.project import get_project_settings


def run_spiders(*spider_classes):
    """Corre los spiders y sale con código 1 si alguno no recorrió el catálogo
    completo, así el worker registra la tarea como error y no como done."""
    process = CrawlerProcess(get_project_settings())
    crawlers = [process.create_crawler(spider_cls) for spider_cls in spider_classes]
    for crawler in crawlers:
        process.crawl(crawler)

    process.start()

    incompletos = [c.spidercls.name for c in crawlers if not c.stats.get_value("catalogo_completo")]
    if incompletos:
        print(f"[run] Catálogo incompleto: {', '.join(incompletos)}", flush=True)
        sys.exit(1)
