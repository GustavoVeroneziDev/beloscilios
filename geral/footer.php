<?php if ($ehPainel ?? false): ?>
</div><!-- /painel-content -->
<?php else: ?>
</main>

<footer class="border-top py-4 mt-5" style="background:var(--bg-card);">
    <div class="container-lg text-center text-secondary small">
        <span style="color:var(--accent);font-weight:600;">Belos Cílios</span> &copy; <?= date('Y') ?>
        &nbsp;·&nbsp; Todos os direitos reservados
    </div>
</footer>
<?php endif ?>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function abrirSidebar() {
    document.getElementById('sidebar').classList.add('aberta');
    document.getElementById('sidebarOverlay').classList.add('ativo');
}
function fecharSidebar() {
    document.getElementById('sidebar')?.classList.remove('aberta');
    document.getElementById('sidebarOverlay')?.classList.remove('ativo');
}

// Auto-dismiss alerts após 5s
document.querySelectorAll('.alert.fade').forEach(el => {
    setTimeout(() => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
        bsAlert?.close();
    }, 5000);
});
</script>
</body>
</html>
