<div id="modulards" class="{{ $theme }} {{ $isConnected ? 'ds-connected' : '' }}">
    <div class="ds-header">
        @if($isWhiteLabelActive)
            <h1 class="ds-title">{{ $title }}</h1>
        @else
            <div class="ds-logo">
                <svg width="300" height="69" viewBox="0 0 300 69" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M34.2857 0.428589C15.3502 0.428589 0 15.7788 0 34.7143C0 53.6498 15.3502 69 34.2857 69C53.2212 69 68.5714 53.6498 68.5714 34.7143C68.5714 15.7788 53.2212 0.428589 34.2857 0.428589ZM46.2858 48.4286C48.1794 48.4286 49.7144 46.8935 49.7144 45V27.8571C49.7144 24.07 46.6443 21 42.8573 21V45C42.8573 46.8935 44.3923 48.4286 46.2858 48.4286ZM34.2858 41.5714C36.1793 41.5714 37.7143 40.0364 37.7143 38.1428V17.5714C37.7143 15.6778 36.1793 14.1428 34.2858 14.1428C32.3922 14.1428 30.8572 15.6778 30.8572 17.5714V38.1428C30.8572 40.0364 32.3922 41.5714 34.2858 41.5714ZM25.7143 45C25.7143 46.8935 24.1792 48.4285 22.2857 48.4285C20.3921 48.4285 18.8571 46.8935 18.8571 45V27.8571C18.8571 24.07 21.9272 21 25.7143 21V45ZM163.155 51.8109C167.193 51.8109 169.776 49.7778 171.607 47.2364V51.3027H177.288V20.367C177.288 18.823 176.016 17.5714 174.447 17.5714C172.878 17.5714 171.607 18.823 171.607 20.367V30.6943C169.822 28.4301 167.24 26.397 163.155 26.397C157.24 26.397 151.652 30.9715 151.652 39.0578V39.1502C151.652 47.2364 157.333 51.8109 163.155 51.8109ZM164.517 46.9592C160.667 46.9592 157.38 43.8633 157.38 39.1502V39.0578C157.38 34.206 160.62 31.2488 164.517 31.2488C168.32 31.2488 171.701 34.3446 171.701 39.0578V39.1502C171.701 43.8171 168.32 46.9592 164.517 46.9592ZM91.3954 51.3027H85.7143V21.8456C85.7143 20.2506 87.0281 18.9577 88.6487 18.9577H91.8649L101.866 34.2522L111.866 18.9577H115.082C116.703 18.9577 118.017 20.2506 118.017 21.8456V51.3027H112.242V28.1067L101.866 43.355H101.678L91.3954 28.1991V51.3027ZM149.012 39.1502C149.012 46.0813 143.378 51.8572 135.725 51.8572C128.166 51.8572 122.579 46.1737 122.579 39.2426V39.1502C122.579 32.1729 128.213 26.397 135.819 26.397C143.425 26.397 149.012 32.0805 149.012 39.0578V39.1502ZM128.26 39.1502C128.26 43.4012 131.406 47.0054 135.819 47.0054C140.42 47.0054 143.331 43.4475 143.331 39.2426V39.1502C143.331 34.8529 140.186 31.295 135.725 31.295C131.171 31.295 128.26 34.8067 128.26 39.0578V39.1502ZM198.788 47.5137C197.191 49.824 194.891 51.8109 191.041 51.8109C185.454 51.8109 182.214 48.1144 182.214 42.4309V29.7008C182.214 28.1569 183.486 26.9053 185.055 26.9053C186.623 26.9053 187.895 28.1569 187.895 29.7008V40.7674C187.895 44.5564 189.82 46.7282 193.201 46.7282C196.487 46.7282 198.788 44.464 198.788 40.675V29.7008C198.788 28.1569 200.06 26.9053 201.628 26.9053C203.197 26.9053 204.469 28.1569 204.469 29.7008V51.3027H198.788V47.5137ZM215.743 51.3027V20.367C215.743 18.823 214.471 17.5714 212.902 17.5714C211.333 17.5714 210.062 18.823 210.062 20.367V51.3027H215.743ZM242.089 36.8398V51.3027H236.454V48.2992C234.764 50.2861 232.182 51.8109 228.426 51.8109C223.731 51.8109 219.599 49.1771 219.599 44.2792V44.1868C219.599 38.7805 223.872 36.1929 229.647 36.1929C232.651 36.1929 234.576 36.6088 236.501 37.2095V36.7474C236.501 33.3743 234.342 31.526 230.398 31.526C228.641 31.526 227.167 31.7676 225.749 32.1801C224.401 32.5724 222.924 31.9401 222.471 30.6303C222.067 29.4619 222.627 28.1652 223.807 27.7468C225.9 27.0046 228.164 26.5356 231.196 26.5356C238.52 26.5356 242.089 30.3246 242.089 36.8398ZM230.022 47.6061C233.778 47.6061 236.595 45.5268 236.595 42.4771V41.0909C235.14 40.5364 233.121 40.1205 230.914 40.1205C227.346 40.1205 225.233 41.553 225.233 43.9095V44.0019C225.233 46.3123 227.346 47.6061 230.022 47.6061ZM252.649 51.3027V42.015C252.649 35.546 256.123 32.3577 261.1 32.3577H261.429V26.4432C257.062 26.2584 254.198 28.7536 252.649 32.4039V29.7008C252.649 28.1569 251.377 26.9053 249.808 26.9053C248.239 26.9053 246.968 28.1569 246.968 29.7008V51.3027H252.649ZM293.775 43.2857C297.432 43.2857 300 41.4286 300 38.1191V38.0714C300 35.1667 298.062 33.9524 294.622 33.0714C291.691 32.3333 290.965 31.9762 290.965 30.881V30.8333C290.965 30.0238 291.716 29.381 293.145 29.381C294.574 29.381 296.052 30 297.553 31.0238L299.491 28.2619C297.771 26.9048 295.664 26.1429 293.193 26.1429C289.729 26.1429 287.258 28.1429 287.258 31.1667V31.2143C287.258 34.5238 289.463 35.4524 292.878 36.3095C295.712 37.0238 296.294 37.5 296.294 38.4286V38.4762C296.294 39.4524 295.373 40.0476 293.847 40.0476C291.909 40.0476 290.311 39.2619 288.784 38.0238L286.58 40.6191C288.615 42.4048 291.207 43.2857 293.775 43.2857ZM270 43.0476H276.613C281.942 43.0476 285.624 39.4048 285.624 34.7143V34.6667C285.624 29.9762 281.942 26.381 276.613 26.381H270V43.0476ZM276.613 39.7381H273.73V29.6905H276.613C279.665 29.6905 281.724 31.7619 281.724 34.7143V34.7619C281.724 37.7143 279.665 39.7381 276.613 39.7381Z" fill="#610BEF"/>
                </svg>
            </div>
        @endif
    </div>

    <div class="ds-content {{ $isWhiteLabelActive || $isConnected ? 'ds-content-sm' : '' }}">
        @if(!$isWhiteLabelActive)
            <div class="ds-tabs">
                <a href="?page=modular-connector" class="ds-tab {{ !isset($_GET['tab']) ? 'ds-tab-active' : '' }}">{{ esc_html__('Connection Manager', 'modular') }}</a>
                <a href="?page=modular-connector&tab=logs" class="ds-tab {{ isset($_GET['tab']) && $_GET['tab'] === 'logs' ? 'ds-tab-active' : '' }}">{{ esc_html__('Logs', 'modular') }}</a>
            </div>
        @endif

        <div class="ds-box">
            <div class="ds-center">
                @yield('content')
            </div>
        </div>

        @if(!$isWhiteLabelActive)
            <span class="ds-decorator ds-circle">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="6" cy="6" r="6" fill="#00E5E5"/>
                </svg>
            </span>
            <span class="ds-decorator ds-cross">
            <svg width="39" height="39" viewBox="0 0 39 39" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path fill-rule="evenodd" clip-rule="evenodd"
                      d="M31.9892 7.22647C33.2907 4.97227 32.5183 2.08984 30.2641 0.788379C28.0099 -0.513083 25.1275 0.259262 23.8261 2.51346L17.7507 13.0363L7.22782 6.96096C4.97362 5.6595 2.0912 6.43184 0.789735 8.68604C-0.511726 10.9402 0.260618 13.8227 2.51481 15.1241L13.0377 21.1995L6.96233 31.7223C5.66087 33.9765 6.43321 36.8589 8.68741 38.1604C10.9416 39.4618 13.824 38.6895 15.1255 36.4353L21.2008 25.9125L31.7236 31.9878C33.9778 33.2893 36.8603 32.517 38.1617 30.2628C39.4632 28.0086 38.6908 25.1261 36.4366 23.8247L25.9138 17.7493L31.9892 7.22647Z"
                      fill="#FF84E5"/>
            </svg>
            </span>
            <span class="ds-decorator ds-triangle">
            <svg width="18" height="21" viewBox="0 0 18 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M17.9948 2.07811C17.9969 1.06925 16.91 0.441775 16.0385 0.948655L1.13544 9.6163C0.26391 10.1232 0.261319 11.3843 1.13077 11.8862L15.9984 20.4701C16.8679 20.972 17.9573 20.3384 17.9593 19.3296L17.9948 2.07811Z"
                      fill="#FFBD63"/>
            </svg>
            </span>
            <span class="ds-decorator ds-churro">
            <svg width="52" height="31" viewBox="0 0 52 31" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path fill-rule="evenodd" clip-rule="evenodd"
                      d="M5.74992 20.6207C20.8254 26.1286 37.5116 18.3726 43.0195 3.29713C43.8064 1.14349 46.1901 0.0354923 48.3438 0.822343C50.4974 1.60919 51.6054 3.99293 50.8186 6.14657C43.7369 25.5293 22.2833 35.5014 2.90048 28.4197C0.746839 27.6329 -0.361162 25.2491 0.42569 23.0955C1.21254 20.9418 3.59628 19.8338 5.74992 20.6207Z"
                      fill="#610BEF"/>
            </svg>
            </span>
        @endif
    </div>
</div>
