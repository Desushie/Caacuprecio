import os
import sys

sys.path.append(r"C:\xampp\htdocs\Caacuprecio\py")
os.environ.setdefault("SCRAPY_SETTINGS_MODULE", "scraper.settings")

from scraper.spiders.fulloffice_productos import FullOfficeProductosSpider
from scraper.utils.runner import run_spiders


def main():
    run_spiders(FullOfficeProductosSpider)


if __name__ == "__main__":
    main()
