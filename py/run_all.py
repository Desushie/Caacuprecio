import os
import sys

sys.path.append(r"C:\xampp\htdocs\Caacuprecio\py")
os.environ.setdefault("SCRAPY_SETTINGS_MODULE", "scraper.settings")

from scraper.spiders.computex_productos import ComputexProductosSpider
from scraper.spiders.fulloffice_productos import FullOfficeProductosSpider
from scraper.spiders.inverfin_productos import InverfinProductosSpider
from scraper.utils.runner import run_spiders


def main():
    # rápidos primero, lento al final
    run_spiders(ComputexProductosSpider, FullOfficeProductosSpider, InverfinProductosSpider)


if __name__ == "__main__":
    main()
