// Builds docs/WP-PLUGINS-GUIDE.docx from docs/WP-PLUGINS-GUIDE.md.
// The Markdown file is the source; rerun this after editing it:
//   node docs/md2docx.js
// (Or edit the .docx by hand in Word; the two are then separate copies.)
// Supports the subset the guide uses: headings, paragraphs, **bold**, `code`,
// bullet and numbered lists, pipe tables, block quotes, code blocks, and ---.

const fs = require('fs');
const path = require('path');
const {
  Document, Packer, Paragraph, TextRun, HeadingLevel, Table, TableRow, TableCell,
  WidthType, ShadingType, BorderStyle, LevelFormat, AlignmentType, Footer, Header,
  PageNumber, TableLayoutType, ImageRun, TabStopType,
} = require('docx');

const SRC = path.join(__dirname, 'WP-PLUGINS-GUIDE.md');
const OUT = path.join(__dirname, 'WP-PLUGINS-GUIDE.docx');

// MD Anderson brand: PMS 485 red, black, Cool Gray 10 / Cool Gray 2, Arial.
const FONT = 'Arial';
const MONO = 'Consolas';
const RED = 'DA291C';       // PMS 485 (matches the logo)
const BLACK = '000000';
const GRAY = '63666A';      // Cool Gray 10
const LIGHT = 'D0D0CE';     // Cool Gray 2
const ZEBRA = 'F3F3F2';     // a light tint of Cool Gray 2
const BRAND = BLACK;        // headings and table headers
const ACCENT = ZEBRA;       // callout fill
const RULE = LIGHT;
const LOGO = fs.readFileSync(path.join(__dirname, 'assets', 'mda-logo.png'));
const LOGO_RATIO = 639 / 1527; // height / width of the logo file
const PAGE_W = 12240, MARGIN = 1296; // US Letter, 0.9" margins
const CONTENT_W = PAGE_W - 2 * MARGIN;

// ---- inline: **bold** and `code` ----
function runs(text, opts = {}) {
  const out = [];
  const re = /(\*\*[^*]+\*\*|`[^`]+`)/g;
  let last = 0, m;
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) out.push(new TextRun({ text: text.slice(last, m.index), font: FONT, ...opts }));
    const tok = m[0];
    if (tok.startsWith('**')) {
      out.push(...runs(tok.slice(2, -2), { ...opts, bold: true }));
    } else {
      out.push(new TextRun({ text: tok.slice(1, -1), font: MONO, size: (opts.size || 21) - 2, ...(opts.bold ? { bold: true } : {}), color: opts.color }));
    }
    last = m.index + tok.length;
  }
  if (last < text.length) out.push(new TextRun({ text: text.slice(last), font: FONT, ...opts }));
  return out;
}

function para(text, extra = {}) {
  return new Paragraph({ children: runs(text), spacing: { after: 120, line: 276 }, ...extra });
}

// ---- tables ----
function splitRow(line) {
  let l = line.trim();
  if (l.startsWith('|')) l = l.slice(1);
  if (l.endsWith('|')) l = l.slice(0, -1);
  return l.split('|').map((c) => c.trim());
}

function buildTable(lines) {
  const rows = lines.filter((l, i) => !(i === 1 && /^\s*\|?\s*:?-{2,}/.test(l))).map(splitRow);
  const header = rows[0];
  const body = rows.slice(1);
  const cols = header.length;
  const blankHeader = header.every((h) => h === '');
  // Column widths: proportional to the longest text in each column, with limits.
  const lens = [];
  for (let c = 0; c < cols; c++) {
    let mx = 4;
    for (const r of rows) mx = Math.max(mx, Math.min((r[c] || '').length, 60));
    lens.push(mx);
  }
  if (cols === 2 && blankHeader) { lens[0] = 18; lens[1] = 60; }
  // Each column gets at least enough room for its longest word (or its
  // header); the space left over is shared out by how much text it holds.
  const longestWord = (c) => Math.max((header[c] || '').length, ...rows.map((r) => Math.max(0, ...(r[c] || '').replace(/[*`]/g, '').split(/\s+/).map((w) => w.length))));
  const mins = lens.map((l, c) => Math.max(900, Math.min(longestWord(c), 22) * 125 + 240));
  const spare = Math.max(0, CONTENT_W - mins.reduce((a, b) => a + b, 0));
  const total = lens.reduce((a, b) => a + b, 0);
  let widths = mins.map((m, c) => m + Math.floor(spare * lens[c] / total));
  widths[widths.length - 1] += CONTENT_W - widths.reduce((a, b) => a + b, 0);

  const border = { style: BorderStyle.SINGLE, size: 4, color: RULE };
  const borders = { top: border, bottom: border, left: border, right: border };
  const mkCell = (text, c, isHead, zebra) => new TableCell({
    width: { size: widths[c], type: WidthType.DXA },
    borders,
    margins: { top: 60, bottom: 60, left: 100, right: 100 },
    shading: isHead ? { type: ShadingType.CLEAR, color: 'auto', fill: BRAND }
      : zebra ? { type: ShadingType.CLEAR, color: 'auto', fill: ZEBRA } : undefined,
    children: [new Paragraph({
      children: runs(text, isHead ? { bold: true, color: 'FFFFFF', size: 20 } : { size: 20 }),
      alignment: text === '☐' ? AlignmentType.CENTER : AlignmentType.LEFT,
    })],
  });
  const trs = [];
  if (!blankHeader) trs.push(new TableRow({ tableHeader: true, children: header.map((h, c) => mkCell(h, c, true)) }));
  body.forEach((r, i) => trs.push(new TableRow({ cantSplit: true, children: Array.from({ length: cols }, (_, c) => mkCell(r[c] || '', c, false, i % 2 === 1)) })));
  return new Table({ width: { size: CONTENT_W, type: WidthType.DXA }, columnWidths: widths, layout: TableLayoutType.FIXED, rows: trs });
}

// ---- main parse ----
const md = fs.readFileSync(SRC, 'utf8').replace(/\r\n/g, '\n').split('\n');
const children = [];
let numberedList = 0; // instance counter so each numbered list restarts at 1
let inNumbered = false;
let title = 'Guide';

for (let i = 0; i < md.length; i++) {
  const line = md[i];
  if (line.trim() === '') { inNumbered = false; continue; }

  if (line.startsWith('```')) {
    const code = [];
    i++;
    while (i < md.length && !md[i].startsWith('```')) code.push(md[i++]);
    for (const c of code) {
      children.push(new Paragraph({
        children: [new TextRun({ text: c || ' ', font: MONO, size: 19 })],
        shading: { type: ShadingType.CLEAR, color: 'auto', fill: 'F2F2F2' },
        spacing: { after: 0 },
        indent: { left: 200, right: 200 },
      }));
    }
    children.push(new Paragraph({ children: [], spacing: { after: 80 } }));
    continue;
  }
  if (/^---+\s*$/.test(line)) {
    continue; // section headings already carry a red rule
  }
  let m;
  if ((m = /^(#{1,3})\s+(.*)$/.exec(line))) {
    const lvl = m[1].length;
    if (lvl === 1) {
      title = m[2];
      children.push(new Paragraph({ children: [new ImageRun({ type: 'png', data: LOGO, transformation: { width: 210, height: Math.round(210 * LOGO_RATIO) }, altText: { title: 'MD Anderson logo', description: 'The University of Texas MD Anderson Cancer Center', name: 'mda-logo' } })], spacing: { after: 360 } }));
      children.push(new Paragraph({ heading: HeadingLevel.TITLE, children: [new TextRun({ text: m[2], font: FONT, bold: true, size: 44, color: BLACK })], spacing: { after: 120 },
        border: { bottom: { style: BorderStyle.SINGLE, size: 18, color: RED, space: 10 } } }));
      children.push(new Paragraph({ children: [], spacing: { after: 200 } }));
    } else {
      children.push(new Paragraph({ heading: lvl === 2 ? HeadingLevel.HEADING_1 : HeadingLevel.HEADING_2, children: runs(m[2]), keepNext: true }));
    }
    continue;
  }
  if (line.trim().startsWith('|')) {
    const block = [];
    while (i < md.length && md[i].trim().startsWith('|')) block.push(md[i++]);
    i--;
    children.push(buildTable(block));
    children.push(new Paragraph({ children: [], spacing: { after: 120 } }));
    continue;
  }
  if (line.startsWith('>')) {
    const block = [];
    while (i < md.length && md[i].startsWith('>')) block.push(md[i++].replace(/^>\s?/, ''));
    i--;
    const box = { style: BorderStyle.SINGLE, size: 18, color: RED, space: 8 };
    for (const b of block) {
      if (b.trim() === '') continue;
      children.push(new Paragraph({
        children: runs(b),
        shading: { type: ShadingType.CLEAR, color: 'auto', fill: ACCENT },
        border: { left: box },
        indent: { left: 240, right: 240 },
        spacing: { after: 60, line: 276 },
      }));
    }
    children.push(new Paragraph({ children: [], spacing: { after: 80 } }));
    continue;
  }
  if ((m = /^(\s*)[-*]\s+(.*)$/.exec(line))) {
    const level = m[1].length >= 2 ? 1 : 0;
    children.push(new Paragraph({ numbering: { reference: 'bullets', level }, children: runs(m[2]), spacing: { after: 80, line: 276 } }));
    continue;
  }
  if ((m = /^(\s*)\d+\.\s+(.*)$/.exec(line))) {
    if (!inNumbered) { numberedList++; inNumbered = true; }
    children.push(new Paragraph({ numbering: { reference: 'numbers', level: 0, instance: numberedList }, children: runs(m[2]), spacing: { after: 80, line: 276 } }));
    // An indented continuation block (e.g. a code block inside a list item).
    continue;
  }
  if (/^\s{2,}\S/.test(line) && inNumbered) {
    children.push(para(line.trim(), { indent: { left: 720 } }));
    continue;
  }
  inNumbered = false;
  children.push(para(line));
}

function pageFooter() {
  return new Footer({ children: [new Paragraph({ alignment: AlignmentType.CENTER, children: [
    new TextRun({ text: 'Page ', font: FONT, size: 16, color: GRAY }),
    new TextRun({ children: [PageNumber.CURRENT], font: FONT, size: 16, color: GRAY }),
    new TextRun({ text: ' of ', font: FONT, size: 16, color: GRAY }),
    new TextRun({ children: [PageNumber.TOTAL_PAGES], font: FONT, size: 16, color: GRAY }),
  ] })] });
}

const doc = new Document({
  creator: 'Montez French',
  lastModifiedBy: 'Montez French',
  title,
  description: 'Guide to the MD Anderson WordPress plugins for leadership and IT',
  styles: {
    default: { document: { run: { font: FONT, size: 21 } } },
    paragraphStyles: [
      { id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { font: FONT, size: 30, bold: true, color: BLACK },
        paragraph: { spacing: { before: 360, after: 160 }, outlineLevel: 0,
          border: { bottom: { style: BorderStyle.SINGLE, size: 8, color: RED, space: 4 } } } },
      { id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { font: FONT, size: 24, bold: true, color: RED },
        paragraph: { spacing: { before: 240, after: 100 }, outlineLevel: 1 } },
    ],
  },
  numbering: {
    config: [
      { reference: 'bullets', levels: [
        { level: 0, format: LevelFormat.BULLET, text: '•', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 540, hanging: 270 } } } },
        { level: 1, format: LevelFormat.BULLET, text: '◦', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 1080, hanging: 270 } } } },
      ] },
      { reference: 'numbers', levels: [
        { level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 540, hanging: 320 } } } },
      ] },
    ],
  },
  sections: [{
    properties: { page: { size: { width: PAGE_W, height: 15840 }, margin: { top: 1152, bottom: 1152, left: MARGIN, right: MARGIN } }, titlePage: true },
    // Page 1 already shows the big logo, so its header is empty.
    headers: { first: new Header({ children: [new Paragraph({ children: [] })] }), default: new Header({ children: [new Paragraph({
      tabStops: [{ type: TabStopType.RIGHT, position: CONTENT_W }],
      border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: LIGHT, space: 6 } },
      children: [
        new ImageRun({ type: 'png', data: LOGO, transformation: { width: 96, height: Math.round(96 * LOGO_RATIO) }, altText: { title: 'MD Anderson logo', description: 'MD Anderson logo', name: 'mda-logo-header' } }),
        new TextRun({ text: '\tWordPress Plugins Guide', font: FONT, size: 16, color: GRAY }),
      ] })] }) },
    footers: { first: pageFooter(), default: new Footer({ children: [new Paragraph({ alignment: AlignmentType.CENTER, children: [
      new TextRun({ text: 'Page ', font: FONT, size: 16, color: GRAY }),
      new TextRun({ children: [PageNumber.CURRENT], font: FONT, size: 16, color: GRAY }),
      new TextRun({ text: ' of ', font: FONT, size: 16, color: GRAY }),
      new TextRun({ children: [PageNumber.TOTAL_PAGES], font: FONT, size: 16, color: GRAY }),
    ] })] }) },
    children,
  }],
});

Packer.toBuffer(doc).then((buf) => {
  fs.writeFileSync(OUT, buf);
  console.log('wrote', OUT, buf.length, 'bytes');
});
