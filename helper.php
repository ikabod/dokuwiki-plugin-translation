<?php

/**
 * Translation Plugin: Simple multilanguage plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
class helper_plugin_translation extends DokuWiki_Plugin
{
    public $translations = [];
    public $translationNs = '';
    public $defaultlang = '';
    public $LN = []; // hold native names
    public $opts = []; // display options

    /**
     * Initialize
     */
    public function __construct()
    {
        global $conf;
        require_once(DOKU_INC . 'inc/pageutils.php');
        require_once(DOKU_INC . 'inc/utf8.php');

        $this->loadTranslationNamespaces();

        // load language names
        $this->LN = confToHash(dirname(__FILE__) . '/lang/langnames.txt');

        // display options
        $this->opts = $this->getConf('display');
        $this->opts = explode(',', $this->opts);
        $this->opts = array_map('trim', $this->opts);
        $this->opts = array_fill_keys($this->opts, true);

        // get default translation
        if (empty($conf['lang_before_translation'])) {
            $dfl = $conf['lang'];
        } else {
            $dfl = $conf['lang_before_translation'];
        }
        if (in_array($dfl, $this->translations)) {
            $this->defaultlang = $dfl;
        } else {
            $this->defaultlang = '';
            array_unshift($this->translations, '');
        }

        $this->translationNs = cleanID($this->getConf('translationns'));
        if ($this->translationNs) $this->translationNs .= ':';
    }

    /**
     * Parse 'translations'-setting into $this->translations
     */
    public function loadTranslationNamespaces()
    {
        // load wanted translation into array
        $this->translations = strtolower(str_replace(',', ' ', $this->getConf('translations')));
        $this->translations = array_unique(array_filter(explode(' ', $this->translations)));
        sort($this->translations);
    }

    /**
     * Check if the given ID is a translation and return the language code.
     *
     * @param string $id
     * @return string
     */
    public function getLangPart($id)
    {
        list($lng) = $this->getTransParts($id);
        return $lng;
    }

    /**
     * Check if the given ID is a translation and return the language code and
     * the id part.
     *
     * @param string $id
     * @return array
     */
    public function getTransParts($id)
    {
        $rx = '/^' . $this->translationNs . '(' . join('|', $this->translations) . '):(.*)/';
        if (preg_match($rx, $id, $match)) {
            return array($match[1], $match[2]);
        }
        return array('', $id);
    }

    /**
     * Returns the browser language if it matches with one of the configured
     * languages
     */
    public function getBrowserLang()
    {
        global $conf;
        $langs = $this->translations;
        if (!in_array($conf['lang'], $langs)) {
            $langs[] = $conf['lang'];
        }
        $rx = '/(^|,|:|;|-)(' . join('|', $langs) . ')($|,|:|;|-)/i';
        if (preg_match($rx, $_SERVER['HTTP_ACCEPT_LANGUAGE'], $match)) {
            return strtolower($match[2]);
        }
        return false;
    }

    /**
     * Returns the ID and name to the wanted translation, empty
     * $lng is default lang
     *
     * @param string $lng
     * @param string $idpart
     * @return array
     */
    public function buildTransID($lng, $idpart)
    {
        if ($lng && in_array($lng, $this->translations)) {
            $link = ':' . $this->translationNs . $lng . ':' . $idpart;
            $name = $lng;
        } else {
            $link = ':' . $this->translationNs . $idpart;
            $name = $this->realLC('');
        }
        return array($link, $name);
    }

    /**
     * Returns the real language code, even when an empty one is given
     * (eg. resolves th default language)
     *
     * @param string $lc
     * @return string
     */
    public function realLC($lc)
    {
        global $conf;
        if ($lc) {
            return $lc;
        } elseif (empty($conf['lang_before_translation'])) {
            return $conf['lang'];
        } else {
            return $conf['lang_before_translation'];
        }
    }

    /**
     * Check if current ID should be translated and any GUI
     * should be shown
     *
     * @param string $id
     * @param bool $checkact
     * @return bool
     */
    public function istranslatable($id, $checkact = true)
    {
        global $ACT;

        if ($checkact && $ACT != 'show') return false;
        if ($this->translationNs && strpos($id, $this->translationNs) !== 0) return false;
        $skiptrans = trim($this->getConf('skiptrans'));
        if ($skiptrans && preg_match('/' . $skiptrans . '/ui', ':' . $id)) return false;
        $meta = p_get_metadata($id);
        if (!empty($meta['plugin']['translation']['notrans'])) return false;

        return true;
    }

    /**
     * Return the (localized) about link
     */
    public function showAbout()
    {
        global $ID;

        $curlc = $this->getLangPart($ID);

        $about = $this->getConf('about');
        if ($this->getConf('localabout')) {
            list(/* $lc */, $idpart) = $this->getTransParts($about);
            list($about, /* $name */) = $this->buildTransID($curlc, $idpart);
            $about = cleanID($about);
        }

        $out = '<sup>';
        $out .= html_wikilink($about, '?');
        $out .= '</sup>';

        return $out;
    }

    /**
     * Returns a list of (lc => link) for all existing translations of a page
     *
     * @param $id
     * @return array
     */
    public function getAvailableTranslations($id)
    {
        $result = array();

        list($lc, $idpart) = $this->getTransParts($id);

        foreach ($this->translations as $t) {
            if ($t == $lc) continue; //skip self
            list($link, $name) = $this->buildTransID($t, $idpart);
            if (page_exists($link)) {
                $result[$name] = $link;
            }
        }

        return $result;
    }

    /**
     * Creates an UI for linking to the available and configured translations
     *
     * Can be called from the template or via the ~~TRANS~~ syntax component.
     */
    public function showTranslations()
    {
        global $INFO;

        if (!$this->istranslatable($INFO['id'])) return '';
        $this->checkage();

        list(/* $lc */, $idpart) = $this->getTransParts($INFO['id']);

        $out = '<div class="plugin_translation ' . ($this->getConf('dropdown') ? 'is-dropdown' : '') . '">';

        //show title and about
        if (isset($this->opts['title']) || $this->getConf('about')) {
            $out .= '<span class="title">';
            if (isset($this->opts['title'])) $out .= $this->getLang('translations');
            if ($this->getConf('about')) $out .= $this->showAbout();
            if (isset($this->opts['title'])) $out .= ': ';
            $out .= '</span>';
        }

        $out .= '<ul>';
        foreach ($this->translations as $t) {
            [$type, $text, $attr] = $this->prepareLanguageSelectorItem($t, $idpart, $INFO['id']);
            $out .= '<li class="'.$type.'">';
            $out .= "<$type " . buildAttributes($attr) . ">$text</$type>";
            $out .= '</li>';
        }
        $out .= '</ul>';



        $out .= '</div>';

        return $out;
    }

    /**
     * Return the local name
     *
     * @param $lang
     * @return string
     */
    public function getLocalName($lang)
    {
        if (isset($this->LN[$lang])) {
            return $this->LN[$lang];
        }
        return $lang;
    }

    /**
     * Create a single language selector item
     *
     * @param string $lc The language code of the item
     * @param string $idpart The ID part of the item
     * @param string $current The current ID
     * @return array [$type, $text, $attr]
     */
    protected function prepareLanguageSelectorItem($lc, $idpart, $current)
    {
        list($target, $lang) = $this->buildTransID($lc, $idpart);
        $target = cleanID($target);
        $exists = page_exists($target, '', false);

        $text = '';
        $attr = [
            'class' => $exists ? 'wikilink1' : 'wikilink2',
            'title' => $this->getLocalName($lang),
        ];

        // no link on current page
        if ($current === $target) {
            $type = 'span';
        } else {
            $type = 'a';
            $attr['href'] = wl($target);
        }

        // add flag
        if (isset($this->opts['flag'])) {
            $text .= '<i>' . inlineSVG(DOKU_PLUGIN . 'translation/flags/' . $lang . '.svg', 1024 * 12) . '</i>';
        }

        // decide what to show
        if (isset($this->opts['name'])) {
            $text .= hsc($this->getLocalName($lang));
            if (isset($this->opts['langcode'])) $text .= ' (' . hsc($lang) . ')';
        } elseif (isset($this->opts['langcode'])) {
            $text .= hsc($lang);
        }

        return [$type, $text, $attr];
    }

    /**
     * Checks if the current page is a translation of a page
     * in the default language. Displays a notice when it is
     * older than the original page. Tries to link to a diff
     * with changes on the original since the translation
     */
    public function checkage()
    {
        global $ID;
        global $INFO;
        if (!$this->getConf('checkage')) return;
        if (!$INFO['exists']) return;
        $lng = $this->getLangPart($ID);
        if ($lng == $this->defaultlang) return;

        $rx = '/^' . $this->translationNs . '((' . join('|', $this->translations) . '):)?/';
        $idpart = preg_replace($rx, '', $ID);

        // compare modification times
        list($orig, /* $name */) = $this->buildTransID($this->defaultlang, $idpart);
        $origfn = wikiFN($orig);
        if ($INFO['lastmod'] >= @filemtime($origfn)) return;

        // build the message and display it
        $orig = cleanID($orig);
        $msg = sprintf($this->getLang('outdated'), wl($orig));

        $difflink = $this->getOldDiffLink($orig, $INFO['lastmod']);
        if ($difflink) {
            $msg .= sprintf(' ' . $this->getLang('diff'), $difflink);
        }

        echo '<div class="notify">' . $msg . '</div>';
    }

    /**
     * Get a link to a diff with changes on the original since the translation
     *
     * @param string $id
     * @param int $lastmod
     * @return false|string false id no diff can be found, link otherwise
     */
    public function getOldDiffLink($id, $lastmod)
    {
        // get revision from before translation
        $orev = false;
        $changelog = new \dokuwiki\ChangeLog\PageChangeLog($id);
        $revs = $changelog->getRevisions(0, 100);
        foreach ($revs as $rev) {
            if ($rev < $lastmod) {
                $orev = $rev;
                break;
            }
        }
        if ($orev && !page_exists($id, $orev)) {
            return false;
        }
        $id = cleanID($id);
        return wl($id, array('do' => 'diff', 'rev' => $orev));

    }
}
