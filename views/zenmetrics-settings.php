
<!-- Zenmetrics Settings Page -->
<div class="wrap">

    <!-- API Keys -->
    <div style="background:#fff;padding:20px 30px;margin:30px 0;">
        <h2>Settings for Zenmetrics</h2>
        <p>
            Zenmetrics makes keeping track of your online store effortless.
            Please fill in your API tokens to get valuable insights and discover
            marketing strategies for your online store. <br />
            You can find your tokens on the settings page in the Zenmetrics app.
        </p>
        <?php woocommerce_admin_fields( self::get_settings() ); ?>
        <p>
            To update and verify your tokens, click the
            <strong>Save changes</strong>
            button at the bottom of this page.
        </p>
    </div>

    <!-- Sync past data -->
    <?php if(get_option('zen_verified') == "verified"): ?>
    <div style="background:#fff;padding:20px 30px;margin:30px 0;">
        <h2>Synchronize historical data</h2>
        <p>
            All your historical data (producs, orders and customers) will be
            synchronized to your Zenmetrics dashboard.<br />
            This process can take up to 15 minutes to complete. It has only to
            be done once! All updates will be synced
            <strong>automatically!</strong><br /><br />
            Make sure to <strong style="color:red;">not close this page</strong>
            while you are synchronizing your data.
        </p>

        <?php if(!empty($_GET['start-past'])): ?>
            <script type="text/javascript">
            jQuery(document).ready(function($){

                // Variables
                var product_chunk_number = <?php echo $product_chunk_number ?>;
                var order_chunk_number   = <?php echo $order_chunk_number ?>;
                var total_chunk_number   = product_chunk_number + order_chunk_number;
                var chunk_percentage     = (total_chunk_number > 0) ? 100/total_chunk_number : 100;
                var chunk_url            = "<?php echo admin_url('admin-ajax.php'); ?>";

                // Helpers
                var update_progress = function(i) {
                    percents = Math.round(i * chunk_percentage);
                    $('#zen_status').html('Almost there! Your data is being sent (' + percents +'%).');
                }

                var send_chunk = function(i,chunk_type)
                {
                    var x = (i<order_chunk_number) ? i : i - order_chunk_number;
                    update_progress(i);
                    $.post(chunk_url, {
                        'action': 'zen_sync_' + chunk_type + '_chunk',
                        'this_chunk': x,
                        'items_per_chunk' : <?php echo $items_per_chunk; ?>
                    },
                    function(response)
                    {
                        if (i+1 < order_chunk_number){
                            setTimeout(function(){
                                send_chunk(i+1, 'order');
                            }, 750);
                        }
                        else if (i+1 < total_chunk_number){
                            setTimeout(function(){
                                send_chunk(i+1, 'product');
                            }, 750);
                        }
                        else{
                            $('#zen_spinner').hide()
                            $('#zen_status').html('All your data is synced!');
                        }

                    });
                }

                send_chunk(0, 'order');
            });
            </script>

            <div style="margin-top:30px">
                <div class="lds-css" id="zen_spinner">
                    <div class="lds-ring">
                        <div></div>
                    </div>
                    <style type="text/css">
                    @keyframes lds-ring {
                      0% {
                        transform: rotate(0)
                      }
                      100% {
                        transform: rotate(360deg)
                      }
                    }
                    .lds-ring > div{
                      margin-right: 13px;
                      float: left;
                      width: 10px;
                      height: 10px;
                      border-radius: 50%;
                      border: 3px solid #fff;
                      border-color: #444 transparent transparent transparent;
                      animation: lds-ring 1.5s cubic-bezier(0.5,0,0.5,1) infinite;
                    }
                    .lds-ring > div:nth-child(2) {
                      animation-delay: .195s;
                    }
                    .lds-ring > div:nth-child(3) {
                      animation-delay: .39s;
                    }
                    .lds-ring > div:nth-child(4) {
                      animation-delay: .585s;
                    }
                    </style>
                </div>

                <strong id="zen_status">We are syncing your data!</strong>
            </div>
        <?php else: ?>
            <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=zenmetrics&start-past=true') ?>" class="button">Sync historical data</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
