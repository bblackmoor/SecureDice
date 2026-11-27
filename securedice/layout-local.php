<?php

declare(strict_types=1);

function rpg_site_assets(): void
{
    ?>
    <link rel="icon" href="theme/icon.png">
    <link rel="stylesheet" href="theme/theme.css">
    <link rel="stylesheet" href="theme/standalone.css">
    <?php
}

function rpg_site_header(): void
{
    ?>
    <header class="rpg-site-header">
        <div class="rpg-site-inner">
            <a class="rpg-brand" href="https://www.rpglibrary.org/">
                <img src="theme/icon.png" alt="" width="52" height="52">
                <span>RPG Library</span>
            </a>
            <nav aria-label="RPG Library navigation">
                <a href="https://www.rpglibrary.org/index.php?title=Tools">Tools</a>
            </nav>
        </div>
    </header>
    <?php
}

function rpg_site_footer(): void
{
    ?>
    <footer class="rpg-site-footer">
        <nav class="rpg-site-inner" aria-label="Site information">
            <a href="https://www.rpglibrary.org/index.php?title=RPG_Library:About">About RPG Library</a>
            <a href="https://www.rpglibrary.org/index.php?title=RPG_Library:Privacy_policy">Privacy policy</a>
        </nav>
    </footer>
    <?php
}
