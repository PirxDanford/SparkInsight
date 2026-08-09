<?php
if (!function_exists('renderSparkInsightLogo')) {
    function renderSparkInsightLogo(string $variant = 'icon', bool $decorative = true): void
    {
        $allowedVariants = ['icon', 'brand', 'hero'];
        if (!in_array($variant, $allowedVariants, true)) {
            $variant = 'icon';
        }

        $className = 'si-logo si-logo--' . $variant;
        ?>
        <span class="<?php echo htmlspecialchars($className, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $decorative ? ' aria-hidden="true"' : ' role="img" aria-label="SparkInsight Logo"'; ?>>
            <svg viewBox="0 0 24 24" focusable="false">
                <path class="si-logo-outline" d="M5.25 4C4.28 4 3.5 4.78 3.5 5.75v12.5c0 .97.78 1.75 1.75 1.75h4.05c.64 0 1.25.25 1.7.7l1 1 1-1c.45-.45 1.06-.7 1.7-.7h4.05c.97 0 1.75-.78 1.75-1.75V5.75C20.5 4.78 19.72 4 18.75 4H14.7c-.64 0-1.25.25-1.7.7l-1 1-1-1A2.4 2.4 0 0 0 9.3 4H5.25Z"/>
                <path class="si-logo-page si-logo-page--left" d="M5.2 6.2h6.5c.33 0 .6.27.6.6v11.4c0 .33-.27.6-.6.6H5.6a.4.4 0 0 1-.4-.4V6.6c0-.22.18-.4.4-.4Z"/>
                <path class="si-logo-page si-logo-page--right" d="M18.8 6.2h-6.5a.6.6 0 0 0-.6.6v11.4c0 .33.27.6.6.6h6.1c.22 0 .4-.18.4-.4V6.6a.4.4 0 0 0-.4-.4Z"/>
                <path class="si-logo-gutter" d="M12 6.25v12.45"/>
                <text class="si-logo-letter si-logo-letter--left" x="8.45" y="12.8">S</text>
                <text class="si-logo-letter si-logo-letter--right" x="15.55" y="12.8">I</text>
            </svg>
        </span>
        <?php
    }
}
