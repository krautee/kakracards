# Installation and Setup

## 1) Prepare environment file

```bash
cd /path/to/kakracards
cp .env-sample .env
```

Update `.env` with:
- `GEMINI_API_KEY`
- `MYSQL_*` connection values
- optional `GEMINI_MODEL` and paths

## 2) Create MySQL schema

```bash
mysql -h <host> -u <user> -p <database> < db/migrations/001_initial.sql
```

## 3) Python CLI environment

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r python/requirements.txt
```

Decode one image and insert into DB:

```bash
python python/decode_cards.py --image /absolute/path/to/card.jpg
```

Decode multiple images at once with custom prompt file:

```bash
python python/decode_cards.py \
  --image /abs/path/card1.jpg /abs/path/card2.jpg \
  --prompt-file /abs/path/prompt.md
```

Preview JSON only (no DB insert):

```bash
python python/decode_cards.py --image /abs/path/card.jpg --no-db --json
```

## 4) Run PHP UI

```bash
php -S 127.0.0.1:8000 -t public
```

Then open:
- `http://127.0.0.1:8000/index.php` list/open records
- `http://127.0.0.1:8000/upload.php` upload/decode/review/save
- `http://127.0.0.1:8000/settings.php` edit Gemini prompt used by UI

## Token-use optimization included

- Gemini response constrained to `application/json`
- shared stable prompt from file/settings
- temperature set to `0` for deterministic compact output
