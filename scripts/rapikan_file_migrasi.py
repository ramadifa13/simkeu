from datetime import datetime
from pathlib import Path
import re
import openpyxl

src = Path(r'C:\Users\ramad\Downloads\9a3a63fa-58ae-417c-b372-27217199e370.xlsx')
dst = src.with_name(src.stem + '_dirapikan.xlsx')
wb = openpyxl.load_workbook(src)

issues = []
money_re = re.compile(r'[^0-9-]')
date_re = re.compile(r'(\d{1,2})/(\d{1,2})/(\d{4})')

money_headers = {'Plafon (Rp)*','Angsuran Normal (Rp)*','Total Tagihan (Rp)*','Pokok (Rp)','Bunga (Rp)','Denda (Rp)','Total Diterima (Rp)*','Saldo Kas Fisik (Rp)*','Modal Diterima (Rp)*','Total Wajib Kembali (Rp)*','Modal Dialokasikan (Rp)*','Kewajiban Dapin (Rp)*','Minimum (Rp)','Maksimum (Rp)','Denda per Hari (Rp)*'}
for ws in wb.worksheets:
    if ws.title in ('Petunjuk','Referensi'):
        continue
    headers = {cell.value: cell.column for cell in ws[2]}
    for row in ws.iter_rows(min_row=3):
        for cell in row:
            if isinstance(cell.value, str):
                v = cell.value.strip()
                if cell.column in headers.values() and ws.cell(2, cell.column).value in money_headers and re.search(r'\d', v):
                    try:
                        cell.value = int(money_re.sub('', v))
                    except ValueError:
                        pass
                elif 'Tanggal' in str(ws.cell(2, cell.column).value) and date_re.search(v):
                    m = date_re.search(v)
                    cell.value = datetime(int(m.group(3)), int(m.group(2)), int(m.group(1)))
                    cell.number_format = 'dd/mm/yyyy'

# Remove instructional wording from notes, but keep the underlying data.
for ws in wb.worksheets:
    for row in ws.iter_rows():
        for cell in row:
            if isinstance(cell.value, str) and 'CONTOH' in cell.value.upper():
                cell.value = re.sub(r'(?i)CONTOH\s*[:\-]?\s*', '', cell.value).strip()

ws = wb.create_sheet('Catatan_Pembersihan')
ws.append(['Status', 'Temuan yang masih perlu dikonfirmasi user'])
notes = [
    'Kode Produk pada pinjaman PJN-20261013-0001 masih “-”.',
    'Tanggal cair 09/12/2026 pada beberapa pinjaman tampak perlu dikonfirmasi: apakah maksudnya 09/10/2026.',
    'Tanggal lunas “Sabtu 17/10/2026” sudah dinormalisasi menjadi tanggal Excel.',
    'ID Dapin pada beberapa baris Pendanaan_Dapin masih kosong; wajib diisi unik sebelum import.',
    'Alokasi_Dapin masih menunjuk PJN-CONTOH-001 yang tidak ada pada sheet Pinjaman.',
    'Saldo awal kas masih 0; isi saldo kas fisik sebenarnya pada tanggal cut-off.',
    'Pastikan nama, NIK, nominal, status, dan seluruh riwayat angsuran sudah diverifikasi dari catatan asli.',
]
for note in notes:
    ws.append(['PERLU KONFIRMASI', note])
ws.column_dimensions['A'].width = 22
ws.column_dimensions['B'].width = 110
ws.freeze_panes = 'A2'
wb.save(dst)
print(dst)
