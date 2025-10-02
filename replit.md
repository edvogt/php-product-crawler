# Product Discovery & Extraction Crawler

## Overview
A PHP 8+ product crawler with JSON file-based caching and optional OpenAI-powered scoring that discovers products via sitemaps and category pages, scores pages based on content quality, matches model numbers, and exports to modern styled HTML with search parameters and detailed results.

## Project Architecture
- **crawler.php**: Single-file implementation (~980 LOC) with OOP classes:
  - Config: Configuration management with AI toggle
  - Log: Console logging
  - Db: JSON file-based discovery cache with 24-hour expiry (no database required)
  - Http: HTTP client with gzip, redirects, proper User-Agent
  - OpenAI: GPT-4o-mini powered intelligent page scoring
  - SiteDiscovery: Dual discovery via sitemaps + category crawling
  - ModelMatcher: Model pattern matching against provided list
  - PageScorer: Dual-mode scoring (rule-based or AI-powered) with ≥20 threshold
  - DataExtractor: Description, short description, and image URL extraction
  - App: Main orchestration logic

- **run_crawler.sh**: Bash wrapper with environment variable support
- **.env**: Local environment variables (OPENAI_API_KEY)

## Usage
```bash
# Rule-based scoring (default, fast)
php crawler.php --base=https://example.com --models=models.txt --out=product-results.html

# AI-powered scoring (requires .env with OPENAI_API_KEY)
php crawler.php --base=https://example.com --models=models.txt --out=product-results.html --use-ai

# Force refresh (bypass cache)
php crawler.php --base=https://example.com --models=models.txt --out=product-results.html --force

# Setup .env file for AI scoring
cp .env.example .env
# Edit .env and add your OpenAI API key

# Using bash wrapper with environment variables
BASE_URL=https://example.com MODELS_FILE=models.txt OUT_FILE=product-results.html ./run_crawler.sh
```

## Features
- Dual discovery: XML sitemaps + category page crawling
- JSON file cache reused if < 24 hours old (no database required)
- **Dual scoring modes:**
  - **Rule-based** (default): Fast keyword/element matching, 0-30 points
  - **AI-powered** (--use-ai): GPT-4o-mini intelligent classification, 0-30 points
- Page score threshold: ≥20 points to qualify as product page
- Advanced image extraction: src, data-src, srcset, picture elements
- Icon/placeholder filtering, absolute URLs, max 10 images per product
- Model matching with flexible pattern recognition
- **HTML output with modern CSS styling:**
  - Search parameters display (base URL, models file, scoring mode, etc.)
  - Results summary with product count
  - Product cards with model name, URL, score badge
  - Descriptions and image galleries
  - Responsive design, gradient styling, hover effects
- Respectful crawling: delays, gzip, redirects, User-Agent
- Environment variable support via .env file

## Recent Changes
- 2025-10-02: Changed output from CSV to HTML with modern CSS styling
- 2025-10-02: Added search parameters and scoring display in HTML output
- 2025-10-02: Fixed URL discovery for Panasonic site structure (/av/video/ pattern)
- 2025-10-02: Added base URL crawling and non-product page filtering
- 2025-10-02: Added OpenAI-powered scoring option with GPT-4o-mini
- 2025-10-02: Added .env file support for API key management
- 2025-10-02: Replaced SQLite3 with JSON file-based caching (no database extension required)
- 2025-10-01: Initial implementation with corrected cleanText() regex pattern
