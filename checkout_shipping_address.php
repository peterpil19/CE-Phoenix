<?php
/*
  $Id$

  osCommerce, Open Source E-Commerce Solutions
  http://www.oscommerce.com

  Copyright (c) 2018 osCommerce

  Released under the GNU General Public License
*/

  require('includes/application_top.php');
  
  $OSCOM_Hooks->register('progress');

// if the customer is not logged on, redirect them to the login page
  if (!tep_session_is_registered('customer_id')) {
    $navigation->set_snapshot();
    tep_redirect(tep_href_link('login.php', '', 'SSL'));
  }

// if there is nothing in the customers cart, redirect them to the shopping cart page
  if ($cart->count_contents() < 1) {
    tep_redirect(tep_href_link('shopping_cart.php'));
  }

  // needs to be included earlier to set the success message in the messageStack
  require('includes/languages/' . $language . '/checkout_shipping_address.php');

  require('includes/classes/order.php');
  $order = new order;

// if the order contains only virtual products, forward the customer to the billing page as
// a shipping address is not needed
  if ($order->content_type == 'virtual') {
    if (!tep_session_is_registered('shipping')) tep_session_register('shipping');
    $shipping = false;
    if (!tep_session_is_registered('sendto')) tep_session_register('sendto');
    $sendto = false;
    tep_redirect(tep_href_link('checkout_payment.php', '', 'SSL'));
  }

  $error = false;
  $process = false;
  if (isset($_POST['action']) && ($_POST['action'] == 'submit') && isset($_POST['formid']) && ($_POST['formid'] == $sessiontoken)) {
// process a new shipping address
    if (tep_not_null($_POST['firstname']) && tep_not_null($_POST['lastname']) && tep_not_null($_POST['street_address'])) {
      $process = true;

      if (ACCOUNT_GENDER == 'true') $gender = tep_db_prepare_input($_POST['gender']);
      if (ACCOUNT_COMPANY == 'true') $company = tep_db_prepare_input($_POST['company']);
      $firstname = tep_db_prepare_input($_POST['firstname']);
      $lastname = tep_db_prepare_input($_POST['lastname']);
      $street_address = tep_db_prepare_input($_POST['street_address']);
      if (ACCOUNT_SUBURB == 'true') $suburb = tep_db_prepare_input($_POST['suburb']);
      $postcode = tep_db_prepare_input($_POST['postcode']);
      $city = tep_db_prepare_input($_POST['city']);
      $country = tep_db_prepare_input($_POST['country']);
      if (ACCOUNT_STATE == 'true') {
        if (isset($_POST['zone_id'])) {
          $zone_id = tep_db_prepare_input($_POST['zone_id']);
        } else {
          $zone_id = false;
        }
        $state = tep_db_prepare_input($_POST['state']);
      }

      if (ACCOUNT_GENDER == 'true') {
        if ( ($gender != 'm') && ($gender != 'f') ) {
          $error = true;

          $messageStack->add('checkout_address', ENTRY_GENDER_ERROR);
        }
      }

      if (strlen($firstname) < ENTRY_FIRST_NAME_MIN_LENGTH) {
        $error = true;

        $messageStack->add('checkout_address', ENTRY_FIRST_NAME_ERROR);
      }

      if (strlen($lastname) < ENTRY_LAST_NAME_MIN_LENGTH) {
        $error = true;

        $messageStack->add('checkout_address', ENTRY_LAST_NAME_ERROR);
      }

      if (strlen($street_address) < ENTRY_STREET_ADDRESS_MIN_LENGTH) {
        $error = true;

        $messageStack->add('checkout_address', ENTRY_STREET_ADDRESS_ERROR);
      }

      if (strlen($postcode) < ENTRY_POSTCODE_MIN_LENGTH) {
        $error = true;

        $messageStack->add('checkout_address', ENTRY_POST_CODE_ERROR);
      }

      if (strlen($city) < ENTRY_CITY_MIN_LENGTH) {
        $error = true;

        $messageStack->add('checkout_address', ENTRY_CITY_ERROR);
      }

      if (ACCOUNT_STATE == 'true') {
        $zone_id = 0;
        $check_query = tep_db_query("select count(*) as total from zones where zone_country_id = '" . (int)$country . "'");
        $check = tep_db_fetch_array($check_query);
        $entry_state_has_zones = ($check['total'] > 0);
        if ($entry_state_has_zones == true) {
          $zone_query = tep_db_query("select distinct zone_id from zones where zone_country_id = '" . (int)$country . "' and (zone_name = '" . tep_db_input($state) . "' or zone_code = '" . tep_db_input($state) . "')");
          if (tep_db_num_rows($zone_query) == 1) {
            $zone = tep_db_fetch_array($zone_query);
            $zone_id = $zone['zone_id'];
          } else {
            $error = true;

            $messageStack->add('checkout_address', ENTRY_STATE_ERROR_SELECT);
          }
        } else {
          if (strlen($state) < ENTRY_STATE_MIN_LENGTH) {
            $error = true;

            $messageStack->add('checkout_address', ENTRY_STATE_ERROR);
          }
        }
      }

      if ( (is_numeric($country) == false) || ($country < 1) ) {
        $error = true;

        $messageStack->add('checkout_address', ENTRY_COUNTRY_ERROR);
      }

      if ($error == false) {
        $sql_data_array = array('customers_id' => $customer_id,
                                'entry_firstname' => $firstname,
                                'entry_lastname' => $lastname,
                                'entry_street_address' => $street_address,
                                'entry_postcode' => $postcode,
                                'entry_city' => $city,
                                'entry_country_id' => $country);

        if (ACCOUNT_GENDER == 'true') $sql_data_array['entry_gender'] = $gender;
        if (ACCOUNT_COMPANY == 'true') $sql_data_array['entry_company'] = $company;
        if (ACCOUNT_SUBURB == 'true') $sql_data_array['entry_suburb'] = $suburb;
        if (ACCOUNT_STATE == 'true') {
          if ($zone_id > 0) {
            $sql_data_array['entry_zone_id'] = $zone_id;
            $sql_data_array['entry_state'] = '';
          } else {
            $sql_data_array['entry_zone_id'] = '0';
            $sql_data_array['entry_state'] = $state;
          }
        }

        if (!tep_session_is_registered('sendto')) tep_session_register('sendto');

        tep_db_perform('address_book', $sql_data_array);

        $sendto = tep_db_insert_id();

        if (tep_session_is_registered('shipping')) tep_session_unregister('shipping');

        tep_redirect(tep_href_link('checkout_shipping.php', '', 'SSL'));
      }
// process the selected shipping destination
    } elseif (isset($_POST['address'])) {
      $reset_shipping = false;
      if (tep_session_is_registered('sendto')) {
        if ($sendto != $_POST['address']) {
          if (tep_session_is_registered('shipping')) {
            $reset_shipping = true;
          }
        }
      } else {
        tep_session_register('sendto');
      }

      $sendto = $_POST['address'];

      $check_address_query = tep_db_query("select count(*) as total from address_book where customers_id = '" . (int)$customer_id . "' and address_book_id = '" . (int)$sendto . "'");
      $check_address = tep_db_fetch_array($check_address_query);

      if ($check_address['total'] == '1') {
        if ($reset_shipping == true) tep_session_unregister('shipping');
        tep_redirect(tep_href_link('checkout_shipping.php', '', 'SSL'));
      } else {
        tep_session_unregister('sendto');
      }
    } else {
      if (!tep_session_is_registered('sendto')) tep_session_register('sendto');
      $sendto = $customer_default_address_id;

      tep_redirect(tep_href_link('checkout_shipping.php', '', 'SSL'));
    }
  }

// if no shipping destination address was selected, use their own address as default
  if (!tep_session_is_registered('sendto')) {
    $sendto = $customer_default_address_id;
  }

  $breadcrumb->add(NAVBAR_TITLE_1, tep_href_link('checkout_shipping.php', '', 'SSL'));
  $breadcrumb->add(NAVBAR_TITLE_2, tep_href_link('checkout_shipping_address.php', '', 'SSL'));

  $addresses_count = tep_count_customer_address_book_entries();

  require('includes/template_top.php');
?>

<h1 class="display-4"><?php echo HEADING_TITLE; ?></h1>

<?php
  if ($messageStack->size('checkout_address') > 0) {
    echo $messageStack->output('checkout_address');
  }
?>

<?php echo tep_draw_form('checkout_address', tep_href_link('checkout_shipping_address.php', '', 'SSL'), 'post', '', true); ?>

<div class="contentContainer">

<?php
  if ($process == false) {
?>

  <h4><?php echo TABLE_HEADING_SHIPPING_ADDRESS; ?></h4>

  <div class="row">
    <div class="col-sm-8">
      <div class="alert alert-warning" role="alert"><?php echo TEXT_SELECTED_SHIPPING_DESTINATION; ?></div>
    </div>
    <div class="col-sm-4">
      <div class="card mb-2">
        <div class="card-header"><?php echo TITLE_SHIPPING_ADDRESS; ?></div>

        <div class="card-body">
          <?php echo tep_address_label($customer_id, $sendto, true, ' ', '<br />'); ?>
        </div>
      </div>
    </div>
  </div>

  <div class="w-100"></div>

<?php
    if ($addresses_count > 1) {
?>

  <h4><?php echo TABLE_HEADING_ADDRESS_BOOK_ENTRIES; ?></h4>
  
  <div class="alert alert-info" role="alert"><?php echo TEXT_SELECT_OTHER_SHIPPING_DESTINATION; ?></div>

  <div class="row">

<?php
      $radio_buttons = 0;

      $addresses_query = tep_db_query("select address_book_id, entry_firstname as firstname, entry_lastname as lastname, entry_company as company, entry_street_address as street_address, entry_suburb as suburb, entry_city as city, entry_postcode as postcode, entry_state as state, entry_zone_id as zone_id, entry_country_id as country_id from address_book where customers_id = '" . (int)$customer_id . "'");
      while ($addresses = tep_db_fetch_array($addresses_query)) {
        $format_id = tep_get_address_format_id($addresses['country_id']);
?>
      <div class="col-sm-4">
        <div class="card mb-2 <?php echo ($addresses['address_book_id'] == $sendto) ? ' text-white bg-info' : ' bg-light'; ?>">
          <div class="card-header"><?php echo tep_output_string_protected($addresses['firstname'] . ' ' . $addresses['lastname']); ?></div>
          <div class="card-body">
            <?php echo tep_address_format($format_id, $addresses, true, ' ', '<br />'); ?>
          </div>
          <div class="card-footer text-center"><?php echo tep_draw_radio_field('address', $addresses['address_book_id'], ($addresses['address_book_id'] == $sendto)); ?></div>
        </div>
      </div>

<?php
        $radio_buttons++;
      }
?>
  </div>
<?php
    }
  }

  if ($addresses_count < MAX_ADDRESS_BOOK_ENTRIES) {
?>

  <h4><?php echo TABLE_HEADING_NEW_SHIPPING_ADDRESS; ?></h4>

  <div class="alert alert-info" role="alert"><?php echo TEXT_CREATE_NEW_SHIPPING_ADDRESS; ?></div>

  <?php require('includes/modules/checkout_new_address.php'); ?>

<?php
  }
?>

  <div class="buttonSet">
    <div class="text-right"><?php echo tep_draw_hidden_field('action', 'submit') . tep_draw_button(IMAGE_BUTTON_CONTINUE, 'fas fa-angle-right', null, 'primary', null, 'btn-success btn-lg btn-block'); ?></div>
  </div>
  
  <div class="w-100"></div>

  <div class="progressBarHook">
    <?php
    echo $OSCOM_Hooks->call('progress', 'progressBar', $arr = array('style' => 'progress-bar progress-bar-striped progress-bar-animated bg-info', 'markers' => array('position' => 1, 'min' => 0, 'max' => 100, 'now' => 33)));
    ?>  
  </div>
  

<?php
  if ($process == true) {
?>

  <div class="buttonSet">
    <?php echo tep_draw_button(IMAGE_BUTTON_BACK, 'fas fa-angle-left', tep_href_link('checkout_shipping.php', '', 'SSL')); ?>
  </div>

<?php
  }
?>

</div>

</form>

<?php
  require('includes/template_bottom.php');
  require('includes/application_bottom.php');
?>
