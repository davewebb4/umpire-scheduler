<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── CSV import tab ────────────────────────────────────────────
function us_csv_import_tab() {
    $leagues = get_posts( [
        'post_type'   => US_PT_LEAGUE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
    ] );

    if ( empty( $leagues ) ) {
        echo '<div class="notice notice-warning inline"><p>You need to <a href="' . admin_url( 'post-new.php?post_type=' . US_PT_LEAGUE ) . '">create at least one league</a> before importing.</p></div>';
        return;
    }

    if ( isset( $_POST['us_csv_step2'] ) && isset( $_FILES['us_csv_file'] ) ) {
        us_csv_show_mapper( $leagues );
        return;
    }

    if ( isset( $_POST['us_csv_import_submit'] ) ) {
        us_csv_do_import();
    }
    ?>
    <p class="us-import-intro">Upload a CSV file from any league scheduling software. You will be able to map columns before importing.</p>

    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field( 'us_csv_step1', 'us_csv_nonce' ); ?>
        <table class="form-table">
            <tr>
                <th><label for="us_csv_league">League</label></th>
                <td>
                    <select name="us_csv_league" id="us_csv_league" required>
                        <option value="">— select league —</option>
                        <?php foreach ( $leagues as $l ) : ?>
                            <option value="<?php echo $l->ID; ?>"><?php echo esc_html( $l->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="us_csv_file">CSV file</label></th>
                <td>
                    <input type="file" name="us_csv_file" id="us_csv_file" accept=".csv" required />
                    <p class="description">Upload the CSV file exported from your league scheduling software.</p>
                </td>
            </tr>
            <tr>
                <th><label for="us_csv_date_format">Date format hint</label></th>
                <td>
                    <select name="us_csv_date_format" id="us_csv_date_format">
                        <option value="auto">Auto-detect</option>
                        <option value="m/d/Y">MM/DD/YYYY (e.g. 04/05/2026)</option>
                        <option value="d/m/Y">DD/MM/YYYY (e.g. 05/04/2026)</option>
                        <option value="Y-m-d">YYYY-MM-DD (e.g. 2026-04-05)</option>
                        <option value="d-m-Y">DD-MM-YYYY (e.g. 05-04-2026)</option>
                        <option value="M j, Y">Mon D, YYYY (e.g. Apr 5, 2026)</option>
                        <option value="l, F j, Y">Full (e.g. Sunday, April 5, 2026)</option>
                    </select>
                    <p class="description">Only needed if dates are ambiguous.</p>
                </td>
            </tr>
            <tr>
                <th>Double header default</th>
                <td>
                    <label class="us-import-checkbox-label">
                        <input type="checkbox" name="us_csv_dh_default" value="1" />
                        Mark all games in this import as double headers
                    </label>
                    <p class="description">Only use this if every game in the file is a double header. You can also map a column for this in the next step.</p>
                </td>
            </tr>
        </table>
        <p class="submit">
            <input type="submit" name="us_csv_step2" class="button button-primary" value="Next — Map columns &rarr;" />
        </p>
    </form>
    <?php
}

// ── Step 2: Column mapper ─────────────────────────────────────
function us_csv_show_mapper( $leagues ) {
    if ( ! isset( $_POST['us_csv_nonce'] ) || ! wp_verify_nonce( $_POST['us_csv_nonce'], 'us_csv_step1' ) ) {
        echo '<div class="notice notice-error inline"><p>Security check failed.</p></div>';
        return;
    }

    $league_id   = absint( $_POST['us_csv_league']      ?? 0 );
    $date_format = sanitize_text_field( $_POST['us_csv_date_format'] ?? 'auto' );
    $dh_default  = isset( $_POST['us_csv_dh_default'] ) ? '1' : '0';

    if ( ! $league_id || empty( $_FILES['us_csv_file']['tmp_name'] ) ) {
        echo '<div class="notice notice-error inline"><p>Please select a league and upload a file.</p></div>';
        return;
    }

    $rows    = us_read_csv( $_FILES['us_csv_file']['tmp_name'] );
    $headers = array_shift( $rows );

    if ( empty( $headers ) ) {
        echo '<div class="notice notice-error inline"><p>Could not read CSV headers.</p></div>';
        return;
    }

    $tmp_file = wp_upload_dir()['basedir'] . '/us_csv_tmp_' . get_current_user_id() . '.csv';
    copy( $_FILES['us_csv_file']['tmp_name'], $tmp_file );

    $league_name  = get_the_title( $league_id );
    $preview_rows = array_slice( $rows, 0, 3 );

    $our_fields = [
        ''      => '— ignore —',
        'date'  => 'Game date',
        'time'  => 'Game time',
        'home'  => 'Home team',
        'away'  => 'Away team',
        'field' => 'Field / location',
        'dh'    => 'Double header (yes/no or 1/0)',
    ];

    $guesses = [];
    foreach ( $headers as $i => $header ) {
        $h = strtolower( trim( $header ) );
        if ( preg_match( '/date/', $h ) )                              $guesses[$i] = 'date';
        elseif ( preg_match( '/time/', $h ) )                          $guesses[$i] = 'time';
        elseif ( preg_match( '/home/', $h ) )                          $guesses[$i] = 'home';
        elseif ( preg_match( '/away|visitor/', $h ) )                  $guesses[$i] = 'away';
        elseif ( preg_match( '/field|location|venue|park/', $h ) )     $guesses[$i] = 'field';
        elseif ( preg_match( '/double.?header|dh|type|format/', $h ) ) $guesses[$i] = 'dh';
        else                                                            $guesses[$i] = '';
    }
    ?>
    <h3>Map columns — <?php echo esc_html( $league_name ); ?> (<?php echo count( $rows ); ?> rows)</h3>

    <?php if ( $dh_default === '1' ) : ?>
        <div class="notice notice-info inline" style="margin-bottom:16px">
            <p>All games in this import will be marked as double headers. You can still map a column below to override this per row.</p>
        </div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field( 'us_csv_import', 'us_csv_import_nonce' ); ?>
        <input type="hidden" name="us_csv_league_id"   value="<?php echo $league_id; ?>">
        <input type="hidden" name="us_csv_date_format" value="<?php echo esc_attr( $date_format ); ?>">
        <input type="hidden" name="us_csv_tmp_file"    value="<?php echo esc_attr( basename( $tmp_file ) ); ?>">
        <input type="hidden" name="us_csv_dh_default"  value="<?php echo esc_attr( $dh_default ); ?>">

        <table class="wp-list-table widefat us-import-mapper-table">
            <thead>
                <tr>
                    <th>Your CSV column</th>
                    <th>Sample data</th>
                    <th>Maps to</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $headers as $i => $header ) :
                    $sample = '';
                    foreach ( $preview_rows as $row ) {
                        if ( isset( $row[$i] ) && trim( $row[$i] ) !== '' ) {
                            $sample = trim( $row[$i] );
                            break;
                        }
                    }
                ?>
                <tr>
                    <td class="us-import-mapper-table__col"><?php echo esc_html( $header ); ?></td>
                    <td class="us-import-mapper-table__sample"><?php echo esc_html( $sample ?: '—' ); ?></td>
                    <td>
                        <select name="us_col_map[<?php echo $i; ?>]" class="us-import-mapper-table__select">
                            <?php foreach ( $our_fields as $val => $label ) : ?>
                                <option value="<?php echo $val; ?>" <?php selected( $guesses[$i] ?? '', $val ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( ! empty( $preview_rows ) ) : ?>
        <h3>Preview</h3>
        <div class="us-import-preview-wrap">
            <table class="wp-list-table widefat">
                <thead>
                    <tr>
                        <?php foreach ( $headers as $h ) : ?>
                            <th><?php echo esc_html( $h ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $preview_rows as $row ) : ?>
                    <tr>
                        <?php foreach ( $headers as $i => $h ) : ?>
                            <td class="us-import-preview-cell"><?php echo esc_html( $row[$i] ?? '' ); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <p>
            <a href="<?php echo admin_url( 'admin.php?page=us-ics-import&tab=csv' ); ?>" class="button">&larr; Start over</a>
            <input type="submit" name="us_csv_import_submit" class="button button-primary us-import-submit" value="Import games &rarr;">
        </p>
    </form>

    <style>
        .us-import-intro            { color: #666; margin-bottom: 20px; }
        .us-import-checkbox-label   { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .us-import-mapper-table     { max-width: 680px; margin-bottom: 24px; }
        .us-import-mapper-table__col    { font-weight: 500; }
        .us-import-mapper-table__sample { color: #666; font-size: 13px; }
        .us-import-mapper-table__select { width: 220px; }
        .us-import-preview-wrap     { overflow-x: auto; margin-bottom: 24px; }
        .us-import-preview-cell     { font-size: 12px; }
        .us-import-submit           { margin-left: 8px; }
    </style>
    <?php
}

// ── Step 3: Do the import ─────────────────────────────────────
function us_csv_do_import() {
    if ( ! isset( $_POST['us_csv_import_nonce'] ) || ! wp_verify_nonce( $_POST['us_csv_import_nonce'], 'us_csv_import' ) ) {
        echo '<div class="notice notice-error inline"><p>Security check failed.</p></div>';
        return;
    }

    $league_id    = absint( $_POST['us_csv_league_id']   ?? 0 );
    $date_format  = sanitize_text_field( $_POST['us_csv_date_format'] ?? 'auto' );
    $col_map      = $_POST['us_col_map'] ?? [];
    $dh_default   = sanitize_text_field( $_POST['us_csv_dh_default']  ?? '0' );
    $tmp_basename = sanitize_file_name( $_POST['us_csv_tmp_file']      ?? '' );
    $tmp_file     = wp_upload_dir()['basedir'] . '/' . $tmp_basename;

    if ( ! $league_id || ! file_exists( $tmp_file ) ) {
        echo '<div class="notice notice-error inline"><p>Import data missing. Please start over.</p></div>';
        return;
    }

    $map = [];
    foreach ( $col_map as $col_idx => $field ) {
        if ( $field !== '' ) $map[ $field ] = intval( $col_idx );
    }

    $required = [ 'date', 'home', 'away' ];
    $missing  = array_filter( $required, fn($r) => ! isset( $map[$r] ) );
    if ( ! empty( $missing ) ) {
        echo '<div class="notice notice-error inline"><p>Please map these required fields: <strong>' . implode( ', ', $missing ) . '</strong>.</p></div>';
        @unlink( $tmp_file );
        return;
    }

    $rows    = us_read_csv( $tmp_file );
    $headers = array_shift( $rows );

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $errors  = 0;

    foreach ( $rows as $row ) {
        $raw_date = trim( $row[ $map['date'] ] ?? '' );
        $raw_time = isset( $map['time'] ) ? trim( $row[ $map['time'] ] ?? '' ) : '';
        $home     = sanitize_text_field( trim( $row[ $map['home'] ] ?? '' ) );
        $away     = sanitize_text_field( trim( $row[ $map['away'] ] ?? '' ) );
        $field    = isset( $map['field'] ) ? sanitize_text_field( trim( $row[ $map['field'] ] ?? '' ) ) : '';

        $is_dh = $dh_default;
        if ( isset( $map['dh'] ) ) {
            $raw_dh = strtolower( trim( $row[ $map['dh'] ] ?? '' ) );
            if ( in_array( $raw_dh, [ '1', 'yes', 'y', 'true', 'dh', 'double header', 'double-header' ] ) ) {
                $is_dh = '1';
            } elseif ( in_array( $raw_dh, [ '0', 'no', 'n', 'false', '' ] ) ) {
                $is_dh = '0';
            }
        }

        if ( ! $raw_date || ! $home || ! $away ) { $skipped++; continue; }

        $game_date = us_parse_csv_date( $raw_date, $date_format );
        if ( ! $game_date ) { $errors++; continue; }

        $game_time = $raw_time ? us_parse_csv_time( $raw_time ) : '';

        $existing = get_posts( [
            'post_type'   => US_PT_GAME,
            'numberposts' => 1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'us_game_date', 'value' => $game_date, 'compare' => '=' ],
                [ 'key' => 'us_home_team', 'value' => $home,      'compare' => '=' ],
                [ 'key' => 'us_away_team', 'value' => $away,      'compare' => '=' ],
                [ 'key' => 'us_league_id', 'value' => $league_id, 'compare' => '=' ],
            ],
        ] );

        if ( $existing ) {
            $ex        = $existing[0];
            $old_time  = get_post_meta( $ex->ID, 'us_game_time',     true );
            $old_field = get_post_meta( $ex->ID, 'us_field',         true );
            $old_dh    = get_post_meta( $ex->ID, 'us_double_header', true ) ?: '0';
            $changed   = ( $game_time && $old_time !== $game_time )
                      || ( $field     && $old_field !== $field )
                      || ( $old_dh    !== $is_dh );

            if ( $changed ) {
                if ( $game_time ) update_post_meta( $ex->ID, 'us_game_time',     $game_time );
                if ( $field )     update_post_meta( $ex->ID, 'us_field',         $field );
                update_post_meta( $ex->ID, 'us_double_header', $is_dh );
                $updated++;
            } else {
                $skipped++;
            }
            continue;
        }

        $post_id = wp_insert_post( [
            'post_type'   => US_PT_GAME,
            'post_title'  => $away . ' at ' . $home,
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $post_id ) ) { $errors++; continue; }

        update_post_meta( $post_id, 'us_game_date',     $game_date );
        update_post_meta( $post_id, 'us_game_time',     $game_time );
        update_post_meta( $post_id, 'us_home_team',     $home );
        update_post_meta( $post_id, 'us_away_team',     $away );
        update_post_meta( $post_id, 'us_field',         $field );
        update_post_meta( $post_id, 'us_league_id',     $league_id );
        update_post_meta( $post_id, 'us_double_header', $is_dh );
        $created++;
    }

    @unlink( $tmp_file );

    $parts = [];
    if ( $created ) $parts[] = "<strong>{$created} new game(s) imported</strong>";
    if ( $updated ) $parts[] = "<strong>{$updated} game(s) updated</strong> — assignments preserved";
    if ( $skipped ) $parts[] = "{$skipped} unchanged game(s) skipped";
    if ( $errors )  $parts[] = "<span style='color:#d63638'>{$errors} row(s) could not be parsed</span>";

    $type = ( $created || $updated ) ? 'success' : 'info';
    echo '<div class="notice notice-' . $type . ' inline"><p>' . implode( ' &middot; ', $parts ) . '</p></div>';
}

// ── Read CSV file ─────────────────────────────────────────────
function us_read_csv( $filepath ) {
    $rows = [];
    if ( ( $handle = fopen( $filepath, 'r' ) ) !== false ) {
        while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
            if ( count( $row ) === 1 ) $row = str_getcsv( $row[0], ';' );
            $rows[] = $row;
        }
        fclose( $handle );
    }
    return $rows;
}

// ── Parse date string ─────────────────────────────────────────
function us_parse_csv_date( $raw, $format = 'auto' ) {
    $raw = trim( $raw );
    if ( empty( $raw ) ) return false;

    if ( $format !== 'auto' ) {
        $dt = DateTime::createFromFormat( $format, $raw );
        if ( $dt ) return $dt->format( 'Y-m-d' );
    }

    $formats = [
        'Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y',
        'M j, Y', 'F j, Y', 'l, F j, Y', 'D, M j, Y',
        'd M Y', 'j M Y', 'Y/m/d',
    ];

    foreach ( $formats as $fmt ) {
        $dt = DateTime::createFromFormat( $fmt, $raw );
        if ( $dt && $dt->format( $fmt ) === $raw ) return $dt->format( 'Y-m-d' );
    }

    $ts = strtotime( $raw );
    if ( $ts ) return date( 'Y-m-d', $ts );

    return false;
}

// ── Parse time string ─────────────────────────────────────────
function us_parse_csv_time( $raw ) {
    $raw = trim( $raw );
    if ( empty( $raw ) ) return '';

    $formats = [ 'H:i', 'H:i:s', 'g:i A', 'g:i a', 'g:ia', 'G:i', 'h:i A', 'h:i a' ];
    foreach ( $formats as $fmt ) {
        $dt = DateTime::createFromFormat( $fmt, $raw );
        if ( $dt ) return $dt->format( 'H:i' );
    }

    $ts = strtotime( $raw );
    if ( $ts ) return date( 'H:i', $ts );

    return '';
}