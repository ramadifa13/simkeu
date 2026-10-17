<form method="GET" class="row g-2 mb-3 p-3 bg-light rounded border">
    <div class="col-lg-4 col-md-6">
        <label class="form-label small mb-1">Cari nama/kode</label>
        <input type="search" name="q" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_q); ?>" placeholder="Nama, kode anggota/pinjaman">
    </div>
    <div class="col-lg-2 col-md-6">
        <label class="form-label small mb-1">Jenis pinjaman</label>
        <select name="jenis" class="form-select form-select-sm">
            <option value="">Semua Jenis</option>
            <?php foreach ($jenis_filter as $jenis): ?>
            <option value="<?php echo $jenis['id']; ?>" <?php echo $filter_jenis === (int) $jenis['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($jenis['nama_jenis']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-lg-2 col-md-6">
        <label class="form-label small mb-1">Kategori</label>
        <select name="jaminan" class="form-select form-select-sm">
            <option value="">Semua Kategori</option>
            <option value="0" <?php echo $filter_jaminan === '0' ? 'selected' : ''; ?>>Tanpa Jaminan</option>
            <option value="1" <?php echo $filter_jaminan === '1' ? 'selected' : ''; ?>>Jaminan/Gadai</option>
        </select>
    </div>
    <div class="col-lg-2 col-md-6">
        <label class="form-label small mb-1">Lama terlambat</label>
        <select name="telat" class="form-select form-select-sm">
            <option value="">Semua Keterlambatan</option>
            <option value="ringan" <?php echo $filter_telat === 'ringan' ? 'selected' : ''; ?>>1–15 hari</option>
            <option value="sedang" <?php echo $filter_telat === 'sedang' ? 'selected' : ''; ?>>16–30 hari</option>
            <option value="berat" <?php echo $filter_telat === 'berat' ? 'selected' : ''; ?>>Lebih dari 30 hari</option>
        </select>
    </div>
    <div class="col-lg-2 col-md-6">
        <label class="form-label small mb-1">Jatuh tempo mulai</label>
        <input type="date" name="mulai" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_mulai); ?>">
    </div>
    <div class="col-lg-2 col-md-6">
        <label class="form-label small mb-1">Jatuh tempo sampai</label>
        <input type="date" name="sampai" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_sampai); ?>">
    </div>
    <div class="col-lg-4 col-md-6 d-flex align-items-end gap-2">
        <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="denda.php" class="btn btn-secondary btn-sm"><i class="fas fa-redo me-1"></i>Reset</a>
    </div>
</form>
