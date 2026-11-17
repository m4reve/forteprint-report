#!/usr/bin/env python3
from pathlib import Path

from docx import Document
from docx.enum.section import WD_ORIENT
from docx.enum.table import WD_ALIGN_VERTICAL, WD_ROW_HEIGHT_RULE, WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Inches, Pt


ROOT = Path("/Users/dmeshcheryakov/Desktop/sample/bitrix24-google-sheets-sync")
OUTPUT = ROOT / "templates" / "Бланк_заказа_в_типографию_Битрикс24.docx"


def set_cell_margins(cell, top=45, start=55, bottom=45, end=55):
    tc = cell._tc
    tc_pr = tc.get_or_add_tcPr()
    tc_mar = tc_pr.first_child_found_in("w:tcMar")
    if tc_mar is None:
        tc_mar = OxmlElement("w:tcMar")
        tc_pr.append(tc_mar)
    for edge, value in (("top", top), ("start", start), ("bottom", bottom), ("end", end)):
        tag = "w:" + edge
        node = tc_mar.find(qn(tag))
        if node is None:
            node = OxmlElement(tag)
            tc_mar.append(node)
        node.set(qn("w:w"), str(value))
        node.set(qn("w:type"), "dxa")


def set_cell_border(cell, **edges):
    tc_pr = cell._tc.get_or_add_tcPr()
    borders = tc_pr.first_child_found_in("w:tcBorders")
    if borders is None:
        borders = OxmlElement("w:tcBorders")
        tc_pr.append(borders)
    for edge_name, attrs in edges.items():
        edge = borders.find(qn("w:" + edge_name))
        if edge is None:
            edge = OxmlElement("w:" + edge_name)
            borders.append(edge)
        for key, value in attrs.items():
            edge.set(qn("w:" + key), str(value))


def clear_cell(cell):
    cell.text = ""
    paragraph = cell.paragraphs[0]
    paragraph.paragraph_format.space_before = Pt(0)
    paragraph.paragraph_format.space_after = Pt(0)
    paragraph.paragraph_format.line_spacing = 1
    return paragraph


def format_run(run, size=10, bold=False, italic=False, color="000000"):
    run.font.name = "Times New Roman"
    run._element.rPr.rFonts.set(qn("w:eastAsia"), "Times New Roman")
    run.font.size = Pt(size)
    run.font.bold = bold
    run.font.italic = italic
    run.font.color.rgb = None
    color_el = run._element.rPr.find(qn("w:color"))
    if color_el is None:
        color_el = OxmlElement("w:color")
        run._element.rPr.append(color_el)
    color_el.set(qn("w:val"), color)


def add_run(paragraph, text, size=10, bold=False, italic=False, color="000000"):
    run = paragraph.add_run(text)
    format_run(run, size=size, bold=bold, italic=italic, color=color)
    return run


def set_repeat_table_header(row):
    tr_pr = row._tr.get_or_add_trPr()
    tbl_header = OxmlElement("w:tblHeader")
    tbl_header.set(qn("w:val"), "true")
    tr_pr.append(tbl_header)


def set_table_fixed(table):
    tbl_pr = table._tbl.tblPr
    layout = tbl_pr.first_child_found_in("w:tblLayout")
    if layout is None:
        layout = OxmlElement("w:tblLayout")
        tbl_pr.append(layout)
    layout.set(qn("w:type"), "fixed")
    table.autofit = False


def set_column_widths(table, widths_cm):
    """Set both table-grid and cell widths; LibreOffice otherwise equalizes columns."""
    total_width = sum(Cm(width_cm).twips for width_cm in widths_cm)
    tbl_pr = table._tbl.tblPr
    tbl_w = tbl_pr.first_child_found_in("w:tblW")
    if tbl_w is None:
        tbl_w = OxmlElement("w:tblW")
        tbl_pr.insert(0, tbl_w)
    tbl_w.set(qn("w:w"), str(int(total_width)))
    tbl_w.set(qn("w:type"), "dxa")
    for idx, width_cm in enumerate(widths_cm):
        width = Cm(width_cm)
        table.columns[idx].width = width
        grid_col = table._tbl.tblGrid.gridCol_lst[idx]
        grid_col.set(qn("w:w"), str(int(width.twips)))
        for row in table.rows:
            cell = row.cells[idx]
            cell.width = width
            tc_pr = cell._tc.get_or_add_tcPr()
            tc_w = tc_pr.first_child_found_in("w:tcW")
            if tc_w is None:
                tc_w = OxmlElement("w:tcW")
                tc_pr.append(tc_w)
            tc_w.set(qn("w:w"), str(int(width.twips)))
            tc_w.set(qn("w:type"), "dxa")


def remove_table_borders(table):
    for row in table.rows:
        for cell in row.cells:
            set_cell_border(
                cell,
                top={"val": "nil"},
                bottom={"val": "nil"},
                start={"val": "nil"},
                end={"val": "nil"},
                insideH={"val": "nil"},
                insideV={"val": "nil"},
            )


def add_bottom_line(cell, width=8):
    set_cell_border(cell, bottom={"val": "single", "sz": str(width), "color": "000000"})


def add_form_row(table, label, value, height_cm=0.67, value_size=9.5, label_width=3.6):
    row = table.add_row()
    row.height = Cm(height_cm)
    row.height_rule = WD_ROW_HEIGHT_RULE.AT_LEAST
    row.cells[0].width = Cm(label_width)
    row.cells[1].width = Cm(9.2 - label_width)
    for cell in row.cells:
        cell.vertical_alignment = WD_ALIGN_VERTICAL.BOTTOM
        set_cell_margins(cell, top=20, bottom=25, start=35, end=35)
        add_bottom_line(cell)
    p0 = clear_cell(row.cells[0])
    add_run(p0, label, size=10.2)
    p1 = clear_cell(row.cells[1])
    add_run(p1, value, size=value_size)
    return row


def add_option_row(table):
    row = table.add_row()
    row.height = Cm(0.44)
    row.height_rule = WD_ROW_HEIGHT_RULE.EXACTLY
    merged = row.cells[0].merge(row.cells[1])
    p = clear_cell(merged)
    p.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    add_run(p, "до 30 мин     до 1 часа     более 1 часа     более 3 часов", size=7.6)


def add_left_field(parent, label, value, height_cm=0.67, value_size=9.0):
    table = parent.add_table(rows=1, cols=2)
    table.alignment = WD_TABLE_ALIGNMENT.LEFT
    set_table_fixed(table)
    remove_table_borders(table)
    set_column_widths(table, [3.6, 8.95])
    row = table.rows[0]
    row.height = Cm(height_cm)
    row.height_rule = WD_ROW_HEIGHT_RULE.AT_LEAST
    for cell in row.cells:
        cell.vertical_alignment = WD_ALIGN_VERTICAL.BOTTOM
        set_cell_margins(cell, top=20, bottom=25, start=35, end=35)
        add_bottom_line(cell)
    p = clear_cell(row.cells[0])
    add_run(p, label, size=10.2)
    p = clear_cell(row.cells[1])
    add_run(p, value, size=value_size)
    return table


def add_left_options(parent):
    table = parent.add_table(rows=1, cols=1)
    table.alignment = WD_TABLE_ALIGNMENT.LEFT
    set_table_fixed(table)
    remove_table_borders(table)
    set_column_widths(table, [12.55])
    row = table.rows[0]
    row.height = Cm(0.42)
    row.height_rule = WD_ROW_HEIGHT_RULE.EXACTLY
    p = clear_cell(row.cells[0])
    p.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    add_run(p, "до 30 мин     до 1 часа     более 1 часа     более 3 часов", size=7.6)
    return table


def add_line_row(parent, label, segments=1, after_label=None, height_cm=0.68, label_width_cm=4.25,
                 bold_label=False, text_after_segments=None, separator="/"):
    cols = 1 + segments + max(0, segments - 1)
    if after_label:
        cols += 1
    if text_after_segments:
        cols += 1
    table = parent.add_table(rows=1, cols=cols)
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    set_table_fixed(table)
    remove_table_borders(table)
    fixed_width = 12.55
    after_width = 0.42 if after_label else 0
    tail_width = 1.9 if text_after_segments else 0
    separator_width = 0.34 * max(0, segments - 1)
    line_width = (fixed_width - label_width_cm - after_width - tail_width - separator_width) / segments
    widths = [label_width_cm]
    if after_label:
        widths.append(after_width)
    for segment in range(segments):
        widths.append(line_width)
        if segment < segments - 1:
            widths.append(0.34)
    if text_after_segments:
        widths.append(tail_width)
    set_column_widths(table, widths)
    row = table.rows[0]
    row.height = Cm(height_cm)
    row.height_rule = WD_ROW_HEIGHT_RULE.AT_LEAST

    label_cell = row.cells[0]
    label_cell.vertical_alignment = WD_ALIGN_VERTICAL.BOTTOM
    set_cell_margins(label_cell, top=20, bottom=30, start=30, end=25)
    p = clear_cell(label_cell)
    add_run(p, label, size=9.5, bold=bold_label)

    index = 1
    if after_label:
        cell = row.cells[index]
        cell.vertical_alignment = WD_ALIGN_VERTICAL.BOTTOM
        set_cell_margins(cell, top=0, bottom=20, start=0, end=0)
        p = clear_cell(cell)
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        add_run(p, after_label, size=9.4)
        index += 1

    for segment in range(segments):
        line_cell = row.cells[index]
        line_cell.vertical_alignment = WD_ALIGN_VERTICAL.BOTTOM
        set_cell_margins(line_cell, top=20, bottom=28, start=20, end=20)
        add_bottom_line(line_cell)
        clear_cell(line_cell)
        index += 1
        if segment < segments - 1:
            slash_cell = row.cells[index]
            slash_cell.vertical_alignment = WD_ALIGN_VERTICAL.BOTTOM
            set_cell_margins(slash_cell, top=0, bottom=20, start=0, end=0)
            p = clear_cell(slash_cell)
            p.alignment = WD_ALIGN_PARAGRAPH.CENTER
            add_run(p, separator, size=10)
            index += 1

    if text_after_segments:
        cell = row.cells[index]
        cell.vertical_alignment = WD_ALIGN_VERTICAL.BOTTOM
        p = clear_cell(cell)
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        add_run(p, text_after_segments, size=8.8)
    return table


def build_document():
    doc = Document()
    section = doc.sections[0]
    section.orientation = WD_ORIENT.LANDSCAPE
    section.page_width = Cm(29.7)
    section.page_height = Cm(21.0)
    section.top_margin = Cm(0.75)
    section.bottom_margin = Cm(0.7)
    section.left_margin = Cm(1.0)
    section.right_margin = Cm(1.0)
    section.header_distance = Cm(0.3)
    section.footer_distance = Cm(0.3)

    normal = doc.styles["Normal"]
    normal.font.name = "Times New Roman"
    normal._element.rPr.rFonts.set(qn("w:eastAsia"), "Times New Roman")
    normal.font.size = Pt(10)
    normal.paragraph_format.space_after = Pt(0)

    title_style = doc.styles["Title"]
    title_style.font.name = "Times New Roman"
    title_style._element.rPr.rFonts.set(qn("w:eastAsia"), "Times New Roman")
    title_style.font.size = Pt(12.5)
    title_style.font.bold = True
    title_style.font.color.rgb = None
    title_style.paragraph_format.space_before = Pt(0)
    title_style.paragraph_format.space_after = Pt(4)
    title_ppr = title_style._element.get_or_add_pPr()
    title_border = title_ppr.find(qn("w:pBdr"))
    if title_border is not None:
        title_ppr.remove(title_border)

    title = doc.add_paragraph(style="Title")
    title.alignment = WD_ALIGN_PARAGRAPH.CENTER
    add_run(title, "БЛАНК ЗАКАЗА В ТИПОГРАФИЮ", size=12.5, bold=True)

    outer = doc.add_table(rows=1, cols=3)
    outer.alignment = WD_TABLE_ALIGNMENT.CENTER
    set_table_fixed(outer)
    remove_table_borders(outer)
    set_column_widths(outer, [13.15, 0.55, 13.0])
    left = outer.cell(0, 0)
    gap = outer.cell(0, 1)
    right = outer.cell(0, 2)
    for cell in (left, gap, right):
        set_cell_margins(cell, top=0, bottom=0, start=0, end=0)
        cell.vertical_alignment = WD_ALIGN_VERTICAL.TOP
        clear_cell(cell)

    # LEFT SIDE
    number_table = left.add_table(rows=2, cols=5)
    number_table.alignment = WD_TABLE_ALIGNMENT.LEFT
    set_table_fixed(number_table)
    remove_table_borders(number_table)
    widths = [0.8, 4.35, 0.25, 3.5, 3.5]
    set_column_widths(number_table, widths)
    for idx, width in enumerate(widths):
        for row in number_table.rows:
            set_cell_margins(row.cells[idx], top=20, bottom=20, start=35, end=35)
    number_table.rows[0].height = Cm(1.25)
    number_table.rows[0].height_rule = WD_ROW_HEIGHT_RULE.EXACTLY

    p = clear_cell(number_table.cell(0, 0))
    p.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    add_run(p, "№", size=18)

    for idx in (1, 3, 4):
        cell = number_table.cell(0, idx)
        set_cell_border(
            cell,
            top={"val": "single", "sz": "10", "color": "000000"},
            bottom={"val": "single", "sz": "10", "color": "000000"},
            start={"val": "single", "sz": "10", "color": "000000"},
            end={"val": "single", "sz": "10", "color": "000000"},
        )
        cell.vertical_alignment = WD_ALIGN_VERTICAL.CENTER
    p = clear_cell(number_table.cell(0, 1))
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    add_run(p, "{DocumentNumber}", size=11.5)

    p = clear_cell(number_table.cell(1, 3))
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    add_run(p, "виза менеджера", size=6.6)
    p = clear_cell(number_table.cell(1, 4))
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    add_run(p, "виза печатника", size=6.6)
    number_table.rows[1].height = Cm(0.35)
    number_table.rows[1].height_rule = WD_ROW_HEIGHT_RULE.EXACTLY

    left.add_paragraph().paragraph_format.space_after = Pt(1)
    add_left_field(
        left,
        "Заказчик",
        "{ContactFormattedName}",
        height_cm=0.72,
        value_size=8.5,
    )
    add_left_field(left, "Срок готовности", "[КОД: Срок выполнения заказа]", value_size=8.8)
    add_left_field(left, "Материал", "[КОД: Материал заказа]", value_size=8.8)
    add_left_field(left, "Дизайнер", "[КОД: ТЗ на дизайн]", value_size=8.8)
    add_left_options(left)
    add_left_field(left, "Время на дизайн", "[КОД: Время на дизайн]", value_size=8.6)
    add_left_field(
        left,
        "Производство",
        "[КОД: ТЗ заказа в производство]",
        height_cm=1.1,
        value_size=8.6,
    )

    inner = left.add_table(rows=1, cols=4)
    inner.alignment = WD_TABLE_ALIGNMENT.LEFT
    set_table_fixed(inner)
    remove_table_borders(inner)
    set_column_widths(inner, [3.2, 2.7, 3.0, 3.0])
    inner.rows[0].height = Cm(0.72)
    inner.rows[0].height_rule = WD_ROW_HEIGHT_RULE.AT_LEAST
    for idx in range(4):
        inner.cell(0, idx).vertical_alignment = WD_ALIGN_VERTICAL.BOTTOM
        set_cell_margins(inner.cell(0, idx), top=10, bottom=20, start=25, end=25)
        add_bottom_line(inner.cell(0, idx))
    for idx, text in enumerate(("Спуск", "[КОД: Спуск]", "Сделать фото", "[КОД: Фото]")):
        p = clear_cell(inner.cell(0, idx))
        add_run(p, text, size=9.2 if idx % 2 == 0 else 8.2)

    add_left_field(left, "Цветопроба", "[КОД: Наличие цветопробы]", value_size=8.5)
    add_left_field(left, "Доставка", "[КОД: Доставка. Адрес]", value_size=8.5)

    # RIGHT SIDE
    p = right.paragraphs[0]
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.space_after = Pt(2)
    add_run(p, "ПРОИЗВОДСТВО", size=11, bold=True)

    add_line_row(
        right,
        "Печатник:",
        segments=2,
        after_label="С",
        height_cm=0.67,
        label_width_cm=3.75,
        separator="×",
    )
    add_line_row(right, "Материал:", segments=3, height_cm=0.73, label_width_cm=3.75)
    add_line_row(right, "Ширина:", segments=3, height_cm=0.73, label_width_cm=3.75)
    add_line_row(
        right,
        "Погонные метры на приладку/отход:",
        segments=2,
        height_cm=0.78,
        label_width_cm=6.3,
        bold_label=True,
    )
    add_line_row(
        right,
        "Погонные метры на брак:",
        segments=1,
        height_cm=0.76,
        label_width_cm=5.25,
        bold_label=True,
    )
    add_line_row(
        right,
        "Погонные метры на печать:",
        segments=3,
        height_cm=0.76,
        label_width_cm=5.25,
        bold_label=True,
    )

    cal = right.add_table(rows=1, cols=3)
    cal.alignment = WD_TABLE_ALIGNMENT.CENTER
    set_table_fixed(cal)
    remove_table_borders(cal)
    set_column_widths(cal, [3.9, 3.2, 5.0])
    for idx in range(3):
        cal.cell(0, idx).vertical_alignment = WD_ALIGN_VERTICAL.CENTER
        set_cell_margins(cal.cell(0, idx), top=45, bottom=45, start=35, end=35)
    texts = (("Каландр:", True), ("□ В сборе", False), ("□ Только этот заказ", False))
    for idx, (text_value, is_bold) in enumerate(texts):
        p = clear_cell(cal.cell(0, idx))
        add_run(p, text_value, size=9.4, bold=is_bold)

    add_line_row(right, "Терморез, п. м.:", segments=3, height_cm=0.72, label_width_cm=4.4, bold_label=True)
    add_line_row(right, "Плоттерная резка, кв. м.:", segments=3, height_cm=0.72, label_width_cm=5.25, bold_label=True)

    ready = right.add_table(rows=1, cols=4)
    ready.alignment = WD_TABLE_ALIGNMENT.CENTER
    set_table_fixed(ready)
    remove_table_borders(ready)
    widths = [3.1, 3.4, 1.0, 4.0]
    set_column_widths(ready, widths)
    for idx in range(4):
        ready.cell(0, idx).vertical_alignment = WD_ALIGN_VERTICAL.BOTTOM
        set_cell_margins(ready.cell(0, idx), top=35, bottom=30, start=25, end=25)
    p = clear_cell(ready.cell(0, 0))
    add_run(p, "Заказ готов:", size=9.5, bold=True)
    clear_cell(ready.cell(0, 1))
    add_bottom_line(ready.cell(0, 1))
    p = clear_cell(ready.cell(0, 2))
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    add_run(p, "дата", size=8.8, bold=True)
    clear_cell(ready.cell(0, 3))
    add_bottom_line(ready.cell(0, 3))

    add_line_row(
        right,
        "Фактическая площадь печати, кв. м.:",
        segments=3,
        height_cm=0.72,
        label_width_cm=6.35,
        bold_label=True,
    )
    add_line_row(right, "Заказ проверен:", segments=1, height_cm=0.78, label_width_cm=3.9)

    spacer = right.add_paragraph()
    spacer.paragraph_format.space_before = Pt(26)
    spacer.paragraph_format.space_after = Pt(0)

    receipt = right.add_table(rows=1, cols=1)
    receipt.alignment = WD_TABLE_ALIGNMENT.CENTER
    set_table_fixed(receipt)
    remove_table_borders(receipt)
    cell = receipt.cell(0, 0)
    set_cell_margins(cell, top=20, bottom=10, start=20, end=20)
    add_bottom_line(cell)
    p = clear_cell(cell)
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    add_run(p, "Заказ получил, претензий не имею", size=8.8)

    # Remove blank paragraphs automatically added before nested tables where possible.
    for cell in (left, right):
        for paragraph in cell.paragraphs:
            paragraph.paragraph_format.space_before = Pt(0)
            paragraph.paragraph_format.space_after = Pt(0)

    # Metadata and compatibility.
    doc.core_properties.title = "Бланк заказа в типографию"
    doc.core_properties.subject = "Шаблон документа для сделки Битрикс24"
    doc.core_properties.author = "OpenAI Codex"

    settings = doc.settings._element
    compat = settings.find(qn("w:compat"))
    if compat is None:
        compat = OxmlElement("w:compat")
        settings.append(compat)

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    doc.save(str(OUTPUT))
    print(str(OUTPUT))


if __name__ == "__main__":
    build_document()
