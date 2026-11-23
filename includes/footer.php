    </div> <!-- /.app-body -->
</div> <!-- /.app-shell -->

<?php // ?v= makes the browser fetch the current script after a change instead of a stale cached copy (same reason as style.css in header.php). ?>
<script src="/UBMSConnect/assets/js/app.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>
