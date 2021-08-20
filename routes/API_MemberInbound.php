<?php
require_once('/var/app/current/global/funcs/Finance.php');
require_once('/var/app/current/global/funcs/Invoice.php');
require_once('/var/app/current/global/funcs/API.php');
require_once('/var/app/current/global/funcs/Rego.php');
require_once('/var/app/current/global/funcs/Email.php');

function put_member_temp($data)
{
    global $query;
    global $clientID;
    global $globalConfig;
    global $clientNationalParent;
    global $seasonID;
    $datesTemp = array();

    if(isset($data['nationalID']))
    {
        $q = $query->createQuery('get_members_id');
        $query->setSeasonOverride($q);
        $query->addSelect($q, 'id', 'members');
        $query->addSelect($q, 'dateOfBirth', 'members');
        $query->addSelect($q, 'id', 'members_year');
        $query->addWhere($q, 'members.id', 'members_year.member_id', '=', true);
        $query->addWhere($q, 'season_id', 0);
        $query->addWhere($q, 'parentBodyID', $data['nationalID']);
        buildQuery('select', $q);

        //when that unique member is found
        if($query->rows($q) == 1)
        {
            $memberID = $query->getResult($q, 0, 'members.id');

            //check if the member is already invoiced or paid.
            $financialStatus = check_financial_status_eligibility($memberID);

            if ($financialStatus)
            {
                $dob = $query->getResult($q, 0, 'members.dateOfBirth');
                $age = calculate_age($dob, $globalConfig['members_age_calculation']);
                $age = reset($age);

                $token = generate_invoice_token(8);

                $qP = $query->createQuery('get_temp_pc');
                $query->addSelect($qP, 'id', 'finance');
                $query->addSelect($qP, 'age_min', 'finance');
                $query->addSelect($qP, 'age_max', 'finance');
                $query->addWhere($qP, 'finance.active', 1);
                $query->addWhere($qP, 'temp', 1);
                $query->addWhere($qP, 'finance.id', $data['paymentClassID']);
                buildQuery('select', $qP);

                //calculate total price
                if ($query->rows($qP) > 0)
                {
                    $financeID = $query->getResult($qP, 0, 'finance.id');
                    $ageMin = $query->getResult($qP, 0, 'age_min');
                    $ageMax = $query->getResult($qP, 0, 'age_max');

                    $dateIsOK = true;

                    if (!empty($ageMax) && $age > $ageMax)
                    {
                        $dateIsOK = false;
                    }

                    if (!empty($ageMin) && $age < $ageMin)
                    {
                        $dateIsOK = false;
                    }

                    if ($dateIsOK) //$age <= $ageMax && $age >= $ageMin)
                    {
                        $totalPrice = 0;
                        if (isset($data['datesPrices']))
                        {
                            $qD = $query->createQuery('get_temp_date');
                            $query->addSelect($qD, 'date', 'finance_temp_dates');
                            $query->addWhere($qD, 'member_id', $memberID);
                            $query->addWhere($qD, 'finance_id', $financeID);
                            buildQuery('select', $qD);

                            $datesMember = array();

                            if ($query->rows($qD) > 0)
                            {
                                for ($i = 0; $i < $query->rows($qD); $i++)
                                {
                                    $datesMember[] = $query->getResult($qD, $i, 'date');
                                }
                            }

                            $qFTD = $query->createQuery('insert_finance_temp_date');
                            $query->addMultiInsertTable($qFTD, 'finance_temp_dates');
                            $i = 0;

                            $badDateFormat = false;

                            foreach ($data['datesPrices'] as $key => $value)
                            {
                                if (validate_date_format($key))
                                {
                                    if (in_array($key, $datesMember))
                                    {
                                        unset($data['datesPrices'][$key]);
                                    } else
                                    {
                                        $datesTemp[] = $key;
                                        $query->addMultiInsertValues($qFTD, $key, 'NULL');
                                        $query->addMultiInsertValues($qFTD, $key, $clientID);
                                        $query->addMultiInsertValues($qFTD, $key, $memberID);
                                        $query->addMultiInsertValues($qFTD, $key, $data['paymentClassID']);
                                        $query->addMultiInsertValues($qFTD, $key, $token);
                                        $query->addMultiInsertValues($qFTD, $key, $key);
                                        $query->addMultiInsertValues($qFTD, $key, $value);
                                        $i++;
                                    }
                                } else
                                {
                                    unset($data['datesPrices'][$key]);
                                    $badDateFormat = true;
                                }
                            }

                            if ($badDateFormat)
                            {
                                deliver_response('json', 11, array('error' => 'Member is not eligible for payment class due to invalid date submitted.'));
                            }

                            if ($i > 0)
                            {
                                buildQuery('multi-insert', $qFTD);
                            } else
                            {
                                deliver_response('json', 1, array('error' => 'Member already has these dates.'));
                            }

                            $totalPrice = array_sum($data['datesPrices']);
                        } else
                        {
                            deliver_response('json', 1, array('error' => 'No dates were submitted.'));
                        }

                        //create & reconcile a split-level level invoice, $0 at the levels above, and the total price at the club level
                        if ($financeID > 0 && $memberID > 0 && count($datesTemp) > 0)
                        {
                            $qU = $query->createQuery('update_members_year');
                            $query->addUpdate($qU, 'members_year');
                            $query->addUpdateSet($qU, 'members_year.finance_id', $financeID);
                            $query->addUpdateSet($qU, 'payment_method', '8');
                            $query->addUpdateSet($qU, 'payment_date', date('Y-m-d'));
                            $query->addWhere($qU, 'member_id', $memberID);
                            buildQuery('update', $qU);

                            $cVal = ($globalConfig['finance_split_payment'] && $clientNationalParent > 0) ? 'Origin' : '';
                            $notes = ($globalConfig['finance_split_payment'] && $clientNationalParent > 0) ? 'SPLIT-PAYMENT' : '';

                            $invoiceID = drawInvoice($clientID, 'member', 'rego', $memberID, $financeID, $totalPrice, $cVal, $notes,
                                '', 1, date('Y-m-d'), 8, '', '', '', 'CURRENT_TIMESTAMP', $token,
                                true, '', '', '', '');

                            if ($globalConfig['finance_split_payment'])
                            {
                                if (!isset($globalConfig['finance_multi_cap_fees']))
                                {
                                    $qFind = $query->createQuery('find_split_invs_' . $memberID);
                                    $query->setClientOverride($qFind);
                                    $query->setSeasonOverride($qFind);
                                    $query->addSelect($qFind, 'client_id', 'invoices');
                                    $query->addSelect($qFind, 'customAmount', 'invoices');
                                    $query->addSelect($qFind, 'discount_amount', 'invoices');
                                    $query->addFrom($qFind, 'seasons');
                                    $query->addFrom($qFind, 'finance');
                                    $query->addWhere($qFind, 'invoices.season_id', 'seasons.id', '=', true);
                                    $query->addWhere($qFind, 'invoices.reference_id', 'finance.id', '=', true);
                                    $query->addWhere($qFind, 'seasons.active', '1');
                                    $query->addWhere($qFind, 'finance.active', '1');
                                    $query->addWhere($qFind, 'invoices.type', 'member');
                                    $query->addWhere($qFind, 'finance.temp', '0');
                                    $query->addWhereOrVal($qFind, 'invoices.subtype', array('rego', 'upgrade'));
                                    $query->addWhere($qFind, 'invoices.primary_id', $memberID);
                                    buildQuery('select', $qFind);

                                    $allOtherInvs = array();

                                    if ($query->rows($qFind) > 0)
                                    {
                                        for ($f = 0; $f < $query->rows($qFind); $f++)
                                        {
                                            $camt = $query->getResult($qFind, $f, 'customAmount');
                                            $damt = $query->getResult($qFind, $f, 'discount_amount');

                                            if ($camt + $damt > 0)
                                            {
                                                $allOtherInvs[] = $query->getResult($qFind, $f, 'client_id');
                                            }
                                        }
                                    }
                                }

                                // Generate the invoice
                                $invIDs = generate_split_invoices($clientID, $financeID, $memberID, $token); // 5th param is $_POST['nominal']... assumed something to do with active kids or discountArray??
                            }

                            // good
                            deliver_response('json', 1, array('success' => 'true', 'dates_added' => $datesTemp)); //successful

                        }
                    } else
                    {
                        deliver_response('json', 11, array('error' => 'Member is not eligible for payment class e.g. due to age restriction.')); //member is not eligible for payment class e.g. due to age restriction
                    }
                } else
                {
                    deliver_response('json', 7, array('error' => 'Temporary membership type ID not found.'));
                }
            }
        }
        else if($query->rows($q) > 1)
        {
            deliver_response('json', 1, array('error' => 'More than one member was found with this National ID.'));
        }
        else
        {
            deliver_response('json', 7, array('error' => 'Member not found.'));
        }
    }
    else
    {
        deliver_response('json', 1, array('error' => 'National ID was not submitted.'));
    }
}

function check_financial_status_eligibility($memberID)
{
    global $query;
    global $globalConfig;
    global $seasonID;

    // Does the member exist this current season?
    $q = $query->createQuery('check_if_member_exists_current_season');
    $query->addSelect($q,'id','members');
    $query->addFrom($q,'members_year');
    $query->addWhere($q,'members.id','members_year.member_id','=',true);
    $query->addWhere($q,'members.id',$memberID);
    buildQuery('select',$q);

    if($query->rows($q) > 0)
    {
        // Does this member already have an invoice (paid or otherwise)?
        $qi = $query->createQuery('check_if_member_has_an_invoice');
        $query->addSelect($qi, 'inv_paid', 'invoices');
        $query->addSelect($qi, 'temp', 'finance');
        $query->addWhere($qi, 'finance.id', 'reference_id', '=', true);
        $query->addWhere($qi, 'invoices.type', 'member');
        $query->addWhereOrVal($qi, 'subtype', array('rego', 'upgrade'));
        $query->addWhere($qi, 'primary_id', $memberID);
        $query->addOrder($qi, 'date', 'invoices', 'desc');
        buildQuery('select', $qi);

        if ($query->rows($qi) == 0) // doesn't have an invoice - is "Unpaid"
        {
            $ok = true;
        } else
        {
            $paid = $query->getResult($qi, 0, 'inv_paid');
            $temp = $query->getResult($qi, 0, 'finance.temp');

            if ($temp && $paid)
            {
                $usedOverall = 0;
                // Get how many dates this member has THIS SEASON
                $qt = $query->createQuery('get_members_temp_dates');
                $query->addSelect($qt, 'finance_id', 'finance_temp_dates');
                $query->addSelectAggregate($qt, 'COUNT(date)', 'finance_temp_dates', 'c');
                $query->addFrom($qt, 'finance');
                $query->addWhere($qt, 'finance.id', 'finance_id', '=', true);
                $query->addWhere($qt, 'member_id', $memberID);

                /* season-agnostic payment classes should ignore this: */
                $query->addWhere($qt, 'date', get_col_name_by_id('seasons', $seasonID, 'start_time'), '>=');

                $query->addGroup($qt, 'finance_id', 'finance_temp_dates');
                buildQuery('select', $qt);

                if ($query->rows($qt) > 0)
                {
                    for ($i = 0; $i < $query->rows($qt); $i++)
                    {
                        $usedOverall += $query->getResult($qt, $i, 'c');
                    }
                }

                if ($globalConfig['members_temp_max'] != '' && $usedOverall >= $globalConfig['members_temp_max'])
                {
                    //This member has reached the maximum number of days permitted for temporary membership
                    $ok = false;
                    deliver_response('json', 11, array('error' => 'Member has reached the maximum number of days permitted for temporary membership.'));
                } else
                {
                    $ok = true;
                }
            } else
            {
                //This member already has an active membership
                $ok = false;
                deliver_response('json', 11, array('error' => 'Member is not eligible for temporary membership as they are already invoiced or paid for membership.'));
            }
        }
    }
    else
    {
        $ok = false;
        deliver_response('json', 11, array('error' => 'Member not active in the current season.'));
    }

    return $ok;
}

function put_member($data)
{
    global $query;
    global $seasonID;
    global $globalConfig;
    global $clientID;
    global $clientNationalParent;
    global $clientFamilyTree;
    global $clientNSOID;
    global $clientSSOID;
    global $clientNationalSystem;
    global $testAPIKey;

    $reqFields = explode('|', $globalConfig['site_rego_fields']);
    $requireRegoField = array_flip(explode(',', $reqFields[1]));

    if($globalConfig['config_club'] == 1 && !isset($data['clubID']))
    {
        deliver_response('json', 1, array('error' => 'This organisation requires members to be submitted with a valid Club ID.'));
    }
    else
    {
        if(isset($data['nationalID']))
        {
            $q = $query->createQuery('get_member_renew');
            $query->addSelect($q, 'id', 'members');
            $query->addSelect($q, 'firstname', 'members');
            $query->addSelect($q, 'surname', 'members');
            $query->addSelect($q, 'gender', 'members');
            $query->addSelect($q, 'dateOfBirth', 'members');
            $query->addSelect($q, 'addressCountry', 'members');
            $query->addSelect($q, 'parentBodyID', 'members');
            $query->addFrom($q, 'members_year');
            $query->addWhere($q, 'members.id', 'members_year.member_id', '=', true);
            $query->addWhere($q, 'members_year.client_id', $clientID);
            $query->addWhere($q, 'parentBodyID', $data['nationalID']);
            buildQuery('select', $q);

            if($query->rows($q) > 0)
            {
                $memberDetails = $data;

                $firstName = (isset($data['firstName'])) ? $data['firstName'] : $query->getResult($q, 0, 'members.firstname');
                $surname = (isset($data['surname'])) ? $data['surname'] : $query->getResult($q, 0, 'members.surname');
                $gender = (isset($data['gender'])) ? $data['gender'] : $query->getResult($q, 0, 'members.gender');
                $dob = (isset($data['dateOfBirth'])) ? $data['dateOfBirth'] : $query->getResult($q, 0, 'members.dateOfBirth');

                $country = (isset($data['addressCountry'])) ? $data['addressCountry'] : convert_country($query->getResult($q, 0, 'members.addressCountry'));
                $financeID = -1;
                $amount = '';

                if(isset($memberDetails['paymentClassID']))
                {
                    unset($memberDetails['paymentClassID']);
                }

                if(is_numeric(validate_country($data['addressCountry'])))
                {
                    //insert into a holding table
                    $memberID = $query->getResult($q, 0, 'members.id');
                    $memberInboundData = renewMemberInbound($memberDetails, 'up', 'c', 'member', 'edit', '', $financeID, 0, $amount, $firstName, $surname, $gender, $dob, $country);

                    $memberInboundID = $memberInboundData[0];
                    $nationalID = $memberInboundData[1];

                    deliver_response('json', 1, array('success'=>'true','nationalID'=>$nationalID, 'message' => 'Member update accepted. Please allow several minutes to process.'));
                }
                else
                {
                    deliver_response('json', 1, array('error' => 'Member country is invalid.'));
                }

                //deliver_response('json', 1, array('error' => 'Member is already renewed'));
            }
            else
            {
                $clientToSearch = ($clientNSOID == 0) ? $clientSSOID : $clientNSOID ;

                $q = $query->createQuery('get_member_renew');
                $query->addSelect($q, 'id', 'members');
                $query->addSelect($q, 'firstname', 'members');
                $query->addSelect($q, 'surname', 'members');
                $query->addSelect($q, 'gender', 'members');
                $query->addSelect($q, 'dateOfBirth', 'members');
                $query->addSelect($q, 'addressCountry', 'members');
                $query->addSelect($q, 'parentBodyID', 'members');
                $query->addFrom($q, 'members_year');
                $query->addWhere($q, 'parentBodyID', $data['nationalID']);
                $query->addWhere($q, 'members.id', 'members_year.member_id', '=', true);
                $query->addWhere($q,'season_id',0);

                if($clientToSearch > 0)
                {
                    $query->setClientOverride($q);
                    $query->addWhere($q, 'client_id', $clientToSearch);
                }

                $query->setSeasonOverride($q);
                buildQuery('select', $q);

                if($query->rows($q) > 0)
                {
                    $memberDetails = $data;

                    $nationalID = (isset($data['NationalID'])) ? $data['NationalID'] : $query->getResult($q, 0, 'members.parentBodyID');
                    $firstName = (isset($data['firstName'])) ? $data['firstName'] : $query->getResult($q, 0, 'members.firstname');
                    $surname = (isset($data['surname'])) ? $data['surname'] : $query->getResult($q, 0, 'members.surname');
                    $gender = (isset($data['gender'])) ? $data['gender'] : $query->getResult($q, 0, 'members.gender');
                    $dob = (isset($data['dateOfBirth'])) ? $data['dateOfBirth'] : $query->getResult($q, 0, 'members.dateOfBirth');

                    if(isset($requireRegoField['street']) && !isset($data['addressStreet']))
                    {
                        deliver_response('json', 1, array('error' => 'Missing field: Street'));
                    }

                    if(isset($requireRegoField['suburb']) && !isset($data['addressSuburb']))
                    {
                        deliver_response('json', 1, array('error' => 'Missing field: Suburb'));
                    }

                    if(isset($requireRegoField['postcode']) && !isset($data['addressPostCode']))
                    {
                        deliver_response('json', 1, array('error' => 'Missing field: Post code'));
                    }

                    if(isset($requireRegoField['state']) && !isset($data['addressState']))
                    {
                        deliver_response('json', 1, array('error' => 'Missing field: State'));
                    }

                    if(isset($requireRegoField['phoneHome']) && !isset($data['phoneHome']))
                    {
                        deliver_response('json', 1, array('error' => 'Missing field: Home Phone'));
                    }

                    if(isset($requireRegoField['phoneMob']) && !isset($data['phoneMob']))
                    {
                        deliver_response('json', 1, array('error' => 'Missing field: Mobile Phone'));
                    }

                    $country = (isset($data['addressCountry'])) ? $data['addressCountry'] : convert_country($query->getResult($q, 0, 'members.addressCountry'));
                    $amount = '';

                    if(isset($data['paymentClassID']))
                    {
                        $amount = check_payment_class_eligibility($data['paymentClassID'], $dob, 'renew');

                        if(is_numeric($amount))
                        {
                            $financeID = $data['paymentClassID'];
                        }
                        else
                        {
                            deliver_response('json', 1, array('error' => 'Member is not eligible for payment class due to age restriction (2).'));
                        }
                    }
                    else
                    {
                        $financeID = -1;
                    }

                    if(is_numeric(validate_country($data['addressCountry'])))
                    {
                        //insert into a holding table
                        $memberID = $query->getResult($q, 0, 'members.id');
                        $memberInboundData = renewMemberInbound($memberDetails, 'up', 'c', 'member', 'renew', '', $financeID, $memberID, $amount, $firstName, $surname, $gender, $dob, $country);

                        $memberInboundID = $memberInboundData[0];
                        $nationalID = $memberInboundData[1];
                        $memberSystemID = $memberInboundData[2];

                        deliver_response('json', 1, array('success'=>'true','nationalID'=>$nationalID, 'systemID' => $memberSystemID, 'message' => 'Member renewal accepted. Please allow several minutes to process.'));
                    }
                    else
                    {
                        deliver_response('json', 9, array('error' => 'Member country is invalid.'));
                    }
                }
                else
                {
                    deliver_response('json', 1, array('error' => 'Member not found.'));
                }
            }
        }
        else if(isset($data['firstName']) && isset($data['surname']) && isset($data['dateOfBirth']) && !isset($data['nationalID'])) {
            $memberDetails = $data;

            $q = $query->createQuery('get_member_register');
            $query->setSeasonOverride($q);

            if ($clientNationalParent) {
                $clientList = explode(',', $clientFamilyTree);

                $query->setClientOverride($q);
                $query->addWhereOrVal($q, 'members_year.client_id', $clientList);
            }

            $query->addSelectAggregate($q, 'COUNT(members.id)', 'members', 'c');
            $query->addSelect($q, 'parentBodyID', 'members');
            $query->addFrom($q, 'members_year');
            $query->addWhere($q, 'members.id', 'members_year.member_id', '=', true);
            $query->addWhere($q, 'firstname', $data['firstName']);
            $query->addWhere($q, 'surname', $data['surname']);
            $query->addWhere($q, 'dateOfBirth', $data['dateOfBirth']);
            buildQuery('select', $q);

            $qT = $query->createQuery('get_pending_members_register');
            $query->setSeasonOverride($qT);
            $query->setClientOverride($qT);
            $query->addFrom($qT, 'members_import_api_inbound');
            $query->addSelectAggregate($qT, 'COUNT(members_import_api_inbound.id)', 'members_import_api_inbound', 'c');
            $query->addWhere($qT, 'members_import_api_inbound.client_id', $clientID);
            $query->addWhere($qT, 'members_import_api_inbound.firstname', $data['firstName']);
            $query->addWhere($qT, 'members_import_api_inbound.surname', $data['surname']);
            $query->addWhere($qT, 'members_import_api_inbound.dateOfBirth', $data['dateOfBirth']);
            buildQuery('select', $qT);

            if (($query->getResult($q, 0, 'c') == 0 && $query->getResult($qT, 0, 'c') == 0) || isset($data['override_duplicate'])) {

                if (isset($data['paymentClassID'])) {
                    $amount = check_payment_class_eligibility($data['paymentClassID'], $data['dateOfBirth'], 'rego');

                    if (is_numeric($amount)) {
                        $financeID = $data['paymentClassID'];
                    } else {
                        deliver_response('json', 9, array('error' => 'Member is not eligible for payment class e.g. due to age restriction.'));
                    }
                } else {
                    $financeID = -1;
                }

                if (!in_array($data['gender'], array('M', 'F', 'N', 'X'))) {
                    deliver_response('json', 9, array('error' => 'Gender identity is not valid (must be M, F, N or X).'));
                }

                if (!validEmail($data['email'])) {
                    deliver_response('json', 9, array('error' => 'Email is not valid.'));
                }

                if (isset($requireRegoField['street']) && !isset($data['addressStreet'])) {
                    deliver_response('json', 9, array('error' => 'Missing field: Street'));
                }

                if (isset($requireRegoField['suburb']) && !isset($data['addressSuburb'])) {
                    deliver_response('json', 9, array('error' => 'Missing field: Suburb'));
                }

                if (isset($requireRegoField['postcode']) && !isset($data['addressPostCode'])) {
                    deliver_response('json', 9, array('error' => 'Missing field: Post code'));
                }

                if (isset($requireRegoField['state']) && !isset($data['addressState'])) {
                    deliver_response('json', 9, array('error' => 'Missing field: State'));
                }

                if (isset($requireRegoField['phoneHome']) && !isset($data['phoneHome'])) {
                    deliver_response('json', 9, array('error' => 'Missing field: Home Phone'));
                }

                if (isset($requireRegoField['phoneMob']) && !isset($data['phoneMob'])) {
                    deliver_response('json', 9, array('error' => 'Missing field: Mobile Phone'));
                }

                if (is_numeric(validate_country($data['addressCountry'])) === false) {
                    deliver_response('json', 9, array('error' => 'Member country is invalid'));
                }

                // All good if here. Add the member.
                $memberInboundData = registerMemberInbound($memberDetails, 'up', 'c', 'member', 'add', $financeID, $amount);
                $memberInboundID = $memberInboundData[0];
                $nationalID = $memberInboundData[1];
                deliver_response('json', 1, [
                    'success' => 'true',
                    'nationalID' => $nationalID,
                    'message' => 'Member registration accepted. Please allow several minutes to process.'
                ]);
            } else {

                //Member name/dob found in portal/parent body, or pending api insertion
                $users = [];
                $seen = [];
                $seenC = [];

                if ($query->getResult($q, 0, 'c') > 0) {
                    //If member was found in a club
                    $qFind = $query->createQuery('get_existing');
                    $query->setSeasonOverride($qFind);

                    if ($clientNationalParent) {
                        $clientList = explode(',', $clientFamilyTree); //Unused. Why?

                        $query->setClientOverride($qFind);
                        $query->addWhere($qFind, 'clients.peak_system', $clientNationalSystem);
                    }        $query->setClientOverride($q);


                    $query->addSelect($qFind, 'addressPostCode', 'members');
                    $query->addSelect($qFind, 'addressState', 'members');
                    $query->addSelect($qFind, 'gender', 'members');
                    $query->addSelect($qFind, 'parentBodyID', 'members');
                    $query->addSelect($qFind, 'name', 'clients');
                    $query->addFrom($qFind, 'members_year');
                    $query->addWhere($qFind, 'members.id', 'members_year.member_id', '=', true);
                    $query->addWhere($qFind, 'clients.id', 'members_year.client_id', '=', true);
                    $query->addWhere($qFind, 'firstname', $data['firstName']);
                    $query->addWhere($qFind, 'surname', $data['surname']);
                    $query->addWhere($qFind, 'dateOfBirth', $data['dateOfBirth']);
                    buildQuery('select', $qFind);

                    if ($query->rows($qFind) > 0) {

                        for ($f = 0; $f < $query->rows($qFind); $f++) {
                            $parentBodyID = $query->getResult($qFind, $f, 'parentBodyID');
                            $cID = $query->getResult($qFind, $f, 'name');

                            if (isset($seenC[$parentBodyID]) === false || (is_array($seenC[$parentBodyID]) && !in_array($cID, $seenC[$parentBodyID]))) {
                                $seenC[$parentBodyID][] = $cID;
                            }
                        }

                        for ($f = 0; $f < $query->rows($qFind); $f++) {
                            $parentBodyID = $query->getResult($qFind, $f, 'parentBodyID');
                            $addressPostCode = $query->getResult($qFind, $f, 'addressPostCode');
                            $addressState = $query->getResult($qFind, $f, 'addressState');
                            $gender = $query->getResult($qFind, $f, 'gender');

                            if (isset($seen[$parentBodyID]) === false) {
                                $users[] = array('National Member ID' => $parentBodyID, 'Post code' => $addressPostCode, 'State' => $addressState, 'Gender identity' => $gender, 'Organisations' => $seenC[$parentBodyID], 'Status' => 'existing');
                            }
                            $seen[$parentBodyID] = true;
                        }
                    }
                }

                if ($query->getResult($qT, 0, 'c') > 0) {
                    //If member was found in members sent to API but not yet processed
                    $qFind2 = $query->createQuery('get_existing_pending_insertion');
                    $query->setClientOverride($qFind2);
                    $query->addFrom($qFind2, 'members_import_api_inbound');
                    $query->addSelect($qFind2, 'addressPostCode', 'members_import_api_inbound');
                    $query->addSelect($qFind2, 'addressState', 'members_import_api_inbound');
                    $query->addSelect($qFind2, 'gender', 'members_import_api_inbound');
                    $query->addSelect($qFind2, 'nationalID', 'members_import_api_inbound');
                    $query->addWhere($qFind2, 'client_id', $clientID);
                    $query->addWhere($qFind2, 'processed_status', '');
                    $query->addWhere($qFind2, 'firstname', $data['firstName']);
                    $query->addWhere($qFind2, 'surname', $data['surname']);
                    $query->addWhere($qFind2, 'dateOfBirth', $data['dateOfBirth']);
                    buildQuery('select', $qFind2);

                    if ($query->rows($qFind2) > 0) {

                        $clientName = get_col_name_by_id('clients', $clientID, 'name', true);

                        for ($f = 0; $f < $query->rows($qFind2); $f++) {
                            $parentBodyID = $query->getResult($qFind2, $f, 'nationalID');
                            $addressPostCode = $query->getResult($qFind2, $f, 'addressPostCode');
                            $addressState = $query->getResult($qFind2, $f, 'addressState');
                            $gender = $query->getResult($qFind2, $f, 'gender');

                            if (isset($seen[$parentBodyID]) === false) {
                                $users[] = array('National Member ID' => $parentBodyID, 'Post code' => $addressPostCode, 'State' => $addressState, 'Gender identity' => $gender, 'Organisations' => [$clientName], 'status' => 'pending');
                            }
                            $seen[$parentBodyID] = true;
                        }
                    }
                }

                deliver_response('json', 1, array('error' => 'Members already exists with the same full name and date of birth.', 'results' => $users));
            }
        }
        else if (!isset($data['firstName']) || !isset($data['surname']) || !isset($data['dateOfBirth']))
        {
            deliver_response('json', 9, array('error' => 'At least one of the following parameters was missing: first name, last name, date of birth.'));
        }
        else
        {
            deliver_response('json', 9, array('error' => 'The national ID parameter was missing.'));
        }
    }
}

function check_payment_class_eligibility($financeID, $dob, $type)
{
    global $query;
    global $globalConfig;
    global $clientID;

    $die = true;

    if(is_numeric($financeID) && $financeID > 0 && validate_dob_format($dob) && $type == 'rego' || $type == 'renew')
    {
        $qP = $query->createQuery('get_pc');
        $query->setSeasonOverride($qP);
        $query->addSelect($qP, 'amount', 'finance');
        $query->addSelect($qP, 'age_min', 'finance');
        $query->addSelect($qP, 'age_max', 'finance');
        $query->addSelect($qP, 'rego_type', 'finance');
        $query->addWhere($qP, 'finance.id', $financeID);
        $query->addWhere($qP, 'finance.active', 1);
        $query->addWhere($qP, 'finance.temp', 0);
        buildQuery('select', $qP);

        if($query->rows($qP) > 0)
        {
            $die = false;

            $ageMin = $query->getResult($qP, 0, 'age_min');
            $ageMax = $query->getResult($qP, 0, 'age_max');
            $regoType = $query->getResult($qP, 0, 'rego_type');
            $age = calculate_age($dob, $globalConfig['members_age_calculation']);
            $age = reset($age);

            if($regoType == $type || $regoType == 'both')
            {
                if(!empty($ageMax) || !empty($ageMin))
                {
                    if(!empty($ageMax) && $age < $ageMax)
                    {
                        return $query->getResult($qP, 0, 'amount');
                    }
                    else if(!empty($ageMin) && $age > $ageMin)
                    {
                        return $query->getResult($qP, 0, 'amount');
                    }
                    else if($age < $ageMax && $age > $ageMin)
                    {
                        return $query->getResult($qP, 0, 'amount');
                    }
                    else
                    {
                        return false;
                    }
                }
                else
                {
                    return $query->getResult($qP, 0, 'amount');
                }
            }
            else
            {
                return false;
            }
        }
    }

    if($die)
    {
        deliver_response('json', 7); // invalid payment class
    }
}

/**
 * Returns the country id for given country name
 * @param $country
 * @return false|mixed|string
 */
function validate_country($country)
{
    global $query;

    $qCountry = $query->createQuery('get_countries_from_name');
    $query->addSelect($qCountry, 'id', 'countries');
    $query->addWhere($qCountry, 'countries.name', $country, 'LIKE');
    buildQuery('select', $qCountry);

    if($query->rows($qCountry) > 0)
    {
        $country = $query->getResult($qCountry, 0, 'countries.id');
        return $country;
    }
}

/**
 * Returns country name for given country id.
 * @param $country
 * @return false|mixed|string
 */
function convert_country($country)
{
    global $query;
    global $clientID;

    $qCountry = $query->createQuery('get_name_from_countries');
    $query->addSelect($qCountry, 'name', 'countries');
    $query->addWhere($qCountry, 'countries.id', $country);
    buildQuery('select', $qCountry);

    if($query->rows($qCountry) > 0)
    {
        $country = $query->getResult($qCountry, 0, 'countries.name');
        return $country;
    }
}


function validate_date_format($date)
{
    $format = 'Y-m-d';
    $d = DateTime::createFromFormat($format, $date);

    $fromNow = date($format, strtotime('+100 years'));
    $past = date($format, strtotime('-100 years'));

    if($date < $fromNow && $date > $past)
    {
        return true;
    } else
    {
        return false;
    }
}

function validate_dob_format($dob)
{
    $format = 'Y-m-d';
    $d = DateTime::createFromFormat($format, $dob);

    if($dob < date($format))
    {
        return $d && $d->format($format) == $dob;
    }
    else
    {
        return false;
    }
}

function registerMemberInbound($memberDetails, $nationalDirection, $logSubdomain, $logType, $logSubType, $financeID, $amount)
{
    global $query;
    global $clientID;
    global $clientFullName;
    global $seasonID;

    global $globalUserID;
    global $clientNationalSystem;
    global $clientNationalParent;

    global $clientFamilyTree;

    global $globalConfig;

    // Gender check
    $genderArr = array('M','F','N','X');
    $gender = $memberDetails['gender'];

    if(!in_array($gender,$genderArr))  // if no gender specified
    {
        $gender = 'X'; //default to non-specified
    }

    $memberDetails['gender'] = $gender;

    if(isset($memberDetails['firstName']) && !empty($memberDetails['firstName']) && isset($memberDetails['surname']) && !empty(isset($memberDetails['surname'])) && isset($memberDetails['dateOfBirth'])
        && validate_dob_format($memberDetails['dateOfBirth']) && !empty($memberDetails['dateOfBirth']) && isset($memberDetails['gender']) && in_array($memberDetails['gender'],$genderArr)
        && isset($memberDetails['addressCountry']) && is_numeric(validate_country($memberDetails['addressCountry'])) )
    {
        $memberDetails['paymentMethod'] = ($memberDetails['invoice_paid']) ? 8 : 0;
        $memberDetails['clubID'] = (isset($memberDetails['clubID'])) ? $memberDetails['clubID'] : 0;
        $memberDetails['schoolID'] = (isset($memberDetails['schoolID'])) ? $memberDetails['schoolID'] : 0;

        $parentDBID = 0;

        if($clientNationalSystem)
        {
            $parentDBID = generateMemberSystemID($clientNationalSystem);
        }

        $memberDetails['schoolID'] = (isset($memberDetails['schoolID'])) ? $memberDetails['schoolID'] : '0';
        $memberDetails['invoice_paid'] = (isset($memberDetails['invoice_paid'])) ? $memberDetails['invoice_paid'] : '' ;
        $memberDetails['override_duplicate'] = (isset($memberDetails['override_duplicate'])) ? $memberDetails['override_duplicate'] : 0;
        $amount = '';

        $qName = $query->createQuery('insert_member_inbound_new');
        $query->addInsertTable($qName, 'members_import_api_inbound');
        $query->addInsertValues($qName, 'NULL');
        $query->addInsertValues($qName, 'm'); // member type
        $query->addInsertValues($qName, $clientID);
        $query->addInsertValues($qName, $parentDBID); // National id
        $query->addInsertValues($qName, $financeID); //payment class ID
        $query->addInsertValues($qName, 0); //renew ID (0 when registering a new member)
        $query->addInsertValues($qName, $memberDetails['clubID']); //club ID (default to 0)
        $query->addInsertValues($qName, $memberDetails['schoolID']); //school ID (default to 0)
        $query->addInsertValues($qName, trim($memberDetails['firstName']));
        $query->addInsertValues($qName, trim($memberDetails['surname']));
        $query->addInsertValues($qName, $memberDetails['dateOfBirth']);
        $query->addInsertValues($qName, $memberDetails['addressStreet']);
        $query->addInsertValues($qName, $memberDetails['addressSuburb']);
        $query->addInsertValues($qName, $memberDetails['addressPostCode']);
        $query->addInsertValues($qName, $memberDetails['addressState']);
        $query->addInsertValues($qName, $memberDetails['addressCountry']);
        $query->addInsertValues($qName, $memberDetails['phoneHome']);
        $query->addInsertValues($qName, $memberDetails['phoneMob']);
        $query->addInsertValues($qName, $memberDetails['email']);
        $query->addInsertValues($qName, $memberDetails['gender']);
        $query->addInsertValues($qName, $memberDetails['invoice_paid']);
        $query->addInsertValues($qName, $amount);
        $query->addInsertValues($qName, ''); // date paid can't be set
        //$query->addInsertValues($qName, ''); // payment class name
        $query->addInsertValues($qName, $memberDetails['paymentMethod']); //payment method
        $query->addInsertValues($qName, ''); // collected by can't be set
        $query->addInsertValues($qName, ''); // receipt can't be set
        $query->addInsertValues($qName, 'CURRENT_TIMESTAMP'); //time created
        $query->addInsertValues($qName, ''); //processed status
        $query->addInsertValues($qName, '0000-00-00 00:00:00'); //processed time
        $query->addInsertValues($qName, $memberDetails['override_duplicate']);
        buildQuery('insert', $qName);

        $memberInboundID = $query->getLastInsertID('members_import_api_inbound');
        $nationalID = $parentDBID;

        return array($memberInboundID, $nationalID);
    }
    else
    {
        deliver_response('json', 1, array('error' => 'At least one of the following parameters was missing or invalid: First name, Last name, Date of Birth, Gender identity, Country.'));
    }
}

function renewMemberInbound($memberDetails, $nationalDirection, $logSubdomain, $logType, $logSubType, $expiry = false, $financeID, $systemID, $amount, $firstName, $surname, $gender, $dob, $country)
{
    global $query;
    global $clientID;
    global $clientFullName;
    global $seasonID;

    global $globalUserID;
    global $clientNationalSystem;
    global $clientNationalParent;

    global $clientFamilyTree;

    global $globalConfig;

    //$query->toggleDebug();

    // Gender check
    $genderArr = array('M','F','N','X');
    $memberDetails['gender'] = (isset($memberDetails['gender'])) ? $memberDetails['gender'] : $gender;

    if(!in_array($memberDetails['gender'],$genderArr))  // if no gender specified
    {
        $memberDetails['gender'] = 'X'; //default to non-specified
    }

    $memberDetails['firstName'] = (isset($memberDetails['firstName'])) ? $memberDetails['firstName'] : $firstName;
    $memberDetails['surname'] = (isset($memberDetails['surname'])) ? $memberDetails['surname'] : $surname;
    $memberDetails['dateOfBirth'] = (isset($memberDetails['dateOfBirth'])) ? $memberDetails['dateOfBirth'] : $dob;
    $memberDetails['addressCountry'] = (isset($memberDetails['addressCountry'])) ? $memberDetails['addressCountry'] : $country;

    $memberDetails['clubID'] = (isset($memberDetails['clubID'])) ? $memberDetails['clubID'] : 0;

    if(isset($memberDetails['firstName']) && !empty($memberDetails['firstName']) && isset($memberDetails['surname']) && !empty(isset($memberDetails['surname'])) && isset($memberDetails['dateOfBirth'])
        && validate_dob_format($memberDetails['dateOfBirth']) && !empty($memberDetails['dateOfBirth']) && isset($memberDetails['gender']) && in_array($memberDetails['gender'],$genderArr)
        && isset($memberDetails['addressCountry']) && is_numeric(validate_country($memberDetails['addressCountry'])))
    {
        $memberDetails['paymentDate'] = ($memberDetails['paymentDate'] != '' && validate_date_format($memberDetails['paymentDate'])) ? $memberDetails['paymentDate'] : '' ;

        $memberDetails['schoolID'] = (isset($memberDetails['schoolID'])) ? $memberDetails['schoolID'] : '0';
        $memberDetails['override_duplicate'] = (isset($memberDetails['override_duplicate'])) ? $memberDetails['override_duplicate'] : 0;

        $amount = '';

        $qName = $query->createQuery('insert_member_inbound_renewing');
        $query->addInsertTable($qName, 'members_import_api_inbound');
        $query->addInsertValues($qName, 'NULL');
        $query->addInsertValues($qName, 'm'); //member type
        $query->addInsertValues($qName, $clientID);
        $query->addInsertValues($qName, $memberDetails['nationalID']);
        $query->addInsertValues($qName, $financeID);
        $query->addInsertValues($qName, $systemID); //renew ID
        $query->addInsertValues($qName, $memberDetails['clubID']); //club ID (default to 0)
        $query->addInsertValues($qName, $memberDetails['schoolID']);
        $query->addInsertValues($qName, trim($memberDetails['firstName']));
        $query->addInsertValues($qName, trim($memberDetails['surname']));
        $query->addInsertValues($qName, $memberDetails['dateOfBirth']);
        $query->addInsertValues($qName, $memberDetails['addressStreet']);
        $query->addInsertValues($qName, $memberDetails['addressSuburb']);
        $query->addInsertValues($qName, $memberDetails['addressPostCode']);
        $query->addInsertValues($qName, $memberDetails['addressState']);
        $query->addInsertValues($qName, $memberDetails['addressCountry']);
        $query->addInsertValues($qName, $memberDetails['phoneHome']);
        $query->addInsertValues($qName, $memberDetails['phoneMob']);
        $query->addInsertValues($qName, $memberDetails['email']);
        $query->addInsertValues($qName, $memberDetails['gender']);
        $query->addInsertValues($qName, $memberDetails['invoice_paid']);
        $query->addInsertValues($qName, $amount);
        $query->addInsertValues($qName, $memberDetails['paymentDate']);
        $query->addInsertValues($qName, 8); //payment method
        $query->addInsertValues($qName, $memberDetails['collectedBy']);
        $query->addInsertValues($qName, $memberDetails['paymentReceipt']);
        $query->addInsertValues($qName, 'CURRENT_TIMESTAMP'); //time created
        $query->addInsertValues($qName, ''); //processed status
        $query->addInsertValues($qName, '0000-00-00 00:00:00'); //processed time
        $query->addInsertValues($qName, $memberDetails['override_duplicate']);
        buildQuery('insert', $qName);

        $memberInboundID = $query->getLastInsertID('members_import_api_inbound');
        $nationalID = get_col_name_by_id('members_import_api_inbound', $memberInboundID,'nationalID');
        $renewID = get_col_name_by_id('members_import_api_inbound', $memberInboundID,'renew_id');

        return array($memberInboundID, $nationalID, $renewID);

        return $memberInboundID;
    }
    else
    {
        deliver_response('json', 1, array('error' => 'At least one of the following parameters was missing or invalid: First name, Last name, Date of Birth, Gender identity, Country.'));
    }
}

function invoice_handler($theClient, $clientConfig, $clientNationalParent, $financeID, $memberID, $finAmount)
{
    global $query;

    $token = generate_invoice_token(8);

    if($financeID > 0 && $memberID > 0)
    {
        $qU = $query->createQuery('update_members_year_' . $memberID);
        $query->addUpdate($qU, 'members_year');
        $query->addUpdateSet($qU, 'members_year.finance_id', $financeID);
        $query->addUpdateSet($qU, 'payment_method', '8');
        $query->addUpdateSet($qU, 'payment_date', date('Y-m-d'));
        $query->addWhere($qU, 'member_id', $memberID);
        $query->addWhere($qU, 'client_id', $theClient);
        buildQuery('update', $qU);

        $cVal = ($clientConfig['finance_split_payment'] && $clientNationalParent > 0) ? 'Origin' : '';
        $notes = ($clientConfig['finance_split_payment'] && $clientNationalParent > 0) ? 'SPLIT-PAYMENT' : '';

        $invoiceID = drawInvoice($theClient, 'member', 'rego', $memberID, $financeID, $finAmount, $cVal, $notes,
            '', 1, date('Y-m-d'), 8, '', '', '', 'CURRENT_TIMESTAMP', $token,
            true, '', '', '', '');

        if($clientConfig['finance_split_payment'])
        {
            if(!isset($clientConfig['finance_multi_cap_fees']))
            {
                $qFind = $query->createQuery('find_split_invs_' . $memberID);
                $query->setClientOverride($qFind);
                $query->setSeasonOverride($qFind);
                $query->addSelect($qFind, 'client_id', 'invoices');
                $query->addSelect($qFind, 'customAmount', 'invoices');
                $query->addSelect($qFind, 'discount_amount', 'invoices');
                $query->addFrom($qFind, 'seasons');
                $query->addFrom($qFind, 'finance');
                $query->addWhere($qFind, 'invoices.season_id', 'seasons.id', '=', true);
                $query->addWhere($qFind, 'invoices.reference_id', 'finance.id', '=', true);
                $query->addWhere($qFind, 'seasons.active', '1');
                $query->addWhere($qFind, 'finance.active', '1');
                $query->addWhere($qFind, 'invoices.type', 'member');
                $query->addWhere($qFind, 'finance.temp', '0');
                $query->addWhereOrVal($qFind, 'invoices.subtype', array('rego', 'upgrade'));
                $query->addWhere($qFind, 'invoices.primary_id', $memberID);
                buildQuery('select', $qFind);

                $allOtherInvs = array();

                if($query->rows($qFind) > 0)
                {
                    for ($f = 0; $f < $query->rows($qFind); $f++)
                    {
                        $camt = $query->getResult($qFind, $f, 'customAmount');
                        $damt = $query->getResult($qFind, $f, 'discount_amount');

                        if($camt + $damt > 0)
                        {
                            $allOtherInvs[] = $query->getResult($qFind, $f, 'client_id');
                        }
                    }
                }
            }

            // Generate the invoice
            $invIDs = generate_split_invoices($theClient, $financeID, $memberID, $token); // 5th param is $_POST['nominal']... assumed something to do with active kids or discountArray??
        }
    }
}

function process_members_from_api_inbound()
{
    global $query;

    $q = $query->createQuery('get_clients_in_import_inbound_table');
    $query->setClientOverride($q);
    $query->addSelectAggregate($q, 'DISTINCT(client_id)', 'members_import_api_inbound', 'c');
    $query->addWhere($q, 'processed_status', '');
    $query->addWhere($q, 'id', '87','>');
    $query->addLimit($q, 50);
    buildQuery('select', $q);

    $allClients = array();

    if($query->rows($q) > 0)
    {
        for ($i = 0; $i < $query->rows($q); $i++)
        {
            $theClient = $query->getResult($q, $i, 'c');

            $allClients[] = $theClient;

            if(!isset($configArrays[$theClient]))
            {
                $globalConfig = get_configuration('', $theClient);
                $configArrays[$theClient] = $globalConfig;
            }

            if(!isset($deetArrays[$theClient]))
            {
                $deets = get_client_details_array($theClient);
                $deetArrays[$theClient] = $deets;
            }
        }
    }

    foreach($allClients as $theClient)
    {
        $seasonID = getActiveSeason($theClient);
        $clientConfig = $configArrays[$theClient];

        $clientNationalSystem = $deetArrays[$theClient]['National System'];
        $clientNationalParent = $deetArrays[$theClient]['National Parent'];
        $clientFamilyTree = $deetArrays[$theClient]['Family Tree'];

        // 1. Get all members in `members_import_api_inbound` WHERE `nationalID` NOT IN (current database for that sport with season ID = 0)
        // i.e. new members
        $q = $query->createQuery('get_current_members_to_exclude');
        $query->setClientOverride($q);
        $query->setSeasonOverride($q);
        $query->addSelect($q, 'parentBodyID', 'members');
        $query->addFrom($q, 'members_year');
        $query->addWhere($q, 'members_year.client_id', $theClient);
        $query->addWhere($q, 'members_year.season_id', '0');
        $query->addWhere($q, 'members.id', 'members_year.member_id', '=', true);
        $subQ = prepareQuery('select', $q);

        $q = $query->createQuery('get_all_new_members');
        $query->setClientOverride($q);
        $query->addSelect($q, 'id', 'members_import_api_inbound');
        $query->addSelect($q, 'firstname', 'members_import_api_inbound');
        $query->addSelect($q, 'surname', 'members_import_api_inbound');
        $query->addSelect($q, 'nationalID', 'members_import_api_inbound');
        $query->addSelect($q, 'clubID', 'members_import_api_inbound');
        $query->addSelect($q, 'finance_id', 'members_import_api_inbound');
        $query->addSelect($q, 'dateOfBirth', 'members_import_api_inbound');
        $query->addSelect($q, 'addressStreet', 'members_import_api_inbound');
        $query->addSelect($q, 'addressSuburb', 'members_import_api_inbound');
        $query->addSelect($q, 'addressPostCode', 'members_import_api_inbound');
        $query->addSelect($q, 'addressState', 'members_import_api_inbound');
        $query->addSelect($q, 'addressCountry', 'members_import_api_inbound');
        $query->addSelect($q, 'phoneHome', 'members_import_api_inbound');
        $query->addSelect($q, 'phoneMob', 'members_import_api_inbound');
        $query->addSelect($q, 'email', 'members_import_api_inbound');
        $query->addSelect($q, 'gender', 'members_import_api_inbound');
        $query->addSelect($q, 'amount', 'members_import_api_inbound');
        $query->addSelect($q, 'payment_date', 'members_import_api_inbound');
        $query->addSelect($q, 'payment_method', 'members_import_api_inbound');
        $query->addSelect($q, 'collected_by', 'members_import_api_inbound');
        $query->addSelect($q, 'receipt', 'members_import_api_inbound');
        $query->addWhere($q, 'members_import_api_inbound.client_id', $theClient);
        $query->addWhere($q, 'processed_status', '');
        $query->addWhere($q, 'renew_id', 0);
        $query->addWhereNotIn($q, 'nationalID', $subQ);
        buildQuery('select', $q);

        if($query->rows($q) > 0)
        {
            $countries = array();

            $qCountries = $query->createQuery('get_countries');
            $query->addSelect($qCountries, 'id', 'countries');
            $query->addSelect($qCountries, 'name', 'countries');
            buildQuery('select', $qCountries);

            for ($c = 0; $c < $query->rows($qCountries); $c++)
            {
                $cID = $query->getResult($qCountries, $c, 'countries.id');
                $cName = $query->getResult($qCountries, $c, 'countries.name');

                $countries[$cName] = $cID;
            }

            $membersToRegister = array();

            for ($i = 0; $i < $query->rows($q); $i++)
            {
                // Prepare the member info for import
                $import = [];
                $import['parentBodyID'] = $query->getResult($q, $i, 'nationalID');
                $import['clubID'] = $query->getResult($q, $i, 'clubID');
                $import['schoolID'] = 0;
                $import['finance_id'] = $query->getResult($q, $i, 'finance_id');
                $import['firstname'] = $query->getResult($q, $i, 'firstname');
                $import['surname'] = $query->getResult($q, $i, 'surname');
                $import['dateOfBirth'] = $query->getResult($q, $i, 'dateOfBirth');
                $import['addressStreet'] = $query->getResult($q, $i, 'addressStreet');
                $import['addressSuburb'] = $query->getResult($q, $i, 'addressSuburb');
                $import['addressPostCode'] = $query->getResult($q, $i, 'addressPostCode');
                $import['addressState'] = $query->getResult($q, $i, 'addressState');
                $import['addressCountry'] = $query->getResult($q, $i, 'addressCountry');
                $import['phoneHome'] = $query->getResult($q, $i, 'phoneHome');
                $import['phoneMob'] = $query->getResult($q, $i, 'phoneMob');
                $import['email'] = $query->getResult($q, $i, 'email');
                $import['gender'] = $query->getResult($q, $i, 'gender');
                $import['amount'] = $query->getResult($q, $i, 'amount');
                $import['payment_date'] = $query->getResult($q, $i, 'payment_date');
                $import['payment_method'] = $query->getResult($q, $i, 'payment_method');
                $import['collected_by'] = $query->getResult($q, $i, 'collected_by');
                $import['receipt'] = $query->getResult($q, $i, 'receipt');

                $countryName = $import['addressCountry'];
                $import['addressCountry'] = (isset($countries[$countryName])) ? $countries[$countryName] : 1 ;

                $email1 = trim($import['email']);
                $email2 = '';

                $gender = strtoupper(trim($import['gender']));

                if($gender != 'M' && $gender != 'F' && $gender != 'N' && $gender != 'X')
                {
                    $gender = 'X';
                }

                $emails = trim($import['email']);

                if(strpos($emails, ',') !== false)
                {
                    $emailArray = explode(',', $emails);

                    $email1 = $emailArray[0];
                    unset($emailArray[0]);
                    $email2 = implode(',', $emailArray);
                }

                $parentDBID = 0;

                if($clientNationalSystem)
                {
                    $parentDBID = ($import['parentBodyID'] != 0) ? $import['parentBodyID'] : generateMemberSystemID($clientNationalSystem);
                }

                $qName = $query->createQuery('insert_member_'.$i);
                $query->addInsertTable($qName, 'members');
                $query->addInsertValues($qName, 'NULL');
                $query->addInsertValues($qName, 'm');
                $query->addInsertValues($qName, $parentDBID);
                $query->addInsertValues($qName, '');
                $query->addInsertValues($qName, '');
                $query->addInsertValues($qName, trim($import['firstname']));
                $query->addInsertValues($qName, trim($import['surname']));
                $query->addInsertValues($qName, trim($import['firstname']).' '.trim($import['surname']));
                $query->addInsertValues($qName, trim($import['firstname']).' '.trim($import['surname']).' '.trim($import['dateOfBirth']));
                $query->addInsertValues($qName, $import['dateOfBirth']);
                $query->addInsertValues($qName, '0'); // valid dob
                $query->addInsertValues($qName, trim($import['addressStreet']));
                $query->addInsertValues($qName, trim($import['addressSuburb']));
                $query->addInsertValues($qName, $import['addressPostCode']);
                $query->addInsertValues($qName, trim($import['addressState']));
                $query->addInsertValues($qName, $import['addressCountry']);
                $query->addInsertValues($qName, trim($import['phoneHome']));
                $query->addInsertValues($qName, trim($import['phoneMob']));
                $query->addInsertValues($qName, $email1);
                $query->addInsertValues($qName, '0'); // valid email
                $query->addInsertValues($qName, $email2); // secondary emails
                $query->addInsertValues($qName, '1'); // receive updates
                $query->addInsertValues($qName, ''); // cookie
                $query->addInsertValues($qName, $gender);
                $query->addInsertValues($qName, ''); // salt
                $query->addInsertValues($qName, $theClient); // origin
                $query->addInsertValues($qName, '0'); // deceased
                $query->addInsertValues($qName, 'CURRENT_TIMESTAMP');
                buildQuery('insert', $qName);

                $clubID = ($clientConfig['config_club']) ? $import['clubID'] : 0;
                $schoolID = ($clientConfig['config_schools']) ? $import['schoolID'] : 0;

                $memberID = $query->getLastInsertID('members');

                if($clientNationalParent)
                {
                    $clientList = explode(',', $clientFamilyTree);

                    // get all clients
                    $qClients = $query->createQuery('get_all_clients');
                    $query->setClientOverride($qClients);
                    $query->addSelect($qClients,'id','clients');
                    $query->addSelect($qClients,'parent_id','clients');
                    $query->addSelect($qClients,'id','seasons');
                    $query->addWhereOrVal($qClients, 'clients.id', $clientList);
                    $query->addWhere($qClients, 'clients.id', 'seasons.client_id', '=', true);
                    $query->addWhere($qClients, 'seasons.active', 1);
                    buildQuery('select',$qClients);

                    $allCDetails = array();

                    if($query->rows($qClients) > 0)
                    {
                        for($j = 0; $j < $query->rows($qClients); $j++)
                        {
                            $sid = $query->getResult($qClients, $j, 'seasons.id');
                            $pid = $query->getResult($qClients, $j, 'parent_id');
                            $cid = $query->getResult($qClients, $j, 'clients.id');
                            $allCDetails[$cid]['current_season'] = $sid;
                            $allCDetails[$cid]['parent_id'] = $pid;
                        }
                    }

                    addToClientLOL($clientNationalParent, $memberID, $allCDetails, 'w', $theClient, true, '');
                }

                $financeID = ($import['finance_id'] > 0) ? $import['finance_id'] : -1;
                $paymentDate = (isset($import['payment_date'])) ? $import['payment_date'] : '';
                $amount = ($import['amount'] > 0) ? $import['amount'] : -1;

                $qName = $query->createQuery('insert_my_rows_'.$memberID);
                $query->addMultiInsertTable($qName, 'members_year');

                // Insert into members year - zero season
                $query->addMultiInsertValues($qName, 0, 'NULL');
                $query->addMultiInsertValues($qName, 0, $theClient);
                $query->addMultiInsertValues($qName, 0, $memberID);
                $query->addMultiInsertValues($qName, 0, $clubID);
                $query->addMultiInsertValues($qName, 0, $schoolID); // School
                $query->addMultiInsertValues($qName, 0, $seasonID);
                $query->addMultiInsertValues($qName, 0, $financeID);
                $query->addMultiInsertValues($qName, 0, 1);
                $query->addMultiInsertValues($qName, 0, $paymentDate);
                $query->addMultiInsertValues($qName, 0, $import['payment_method']);
                $query->addMultiInsertValues($qName, 0, $import['receipt']);
                $query->addMultiInsertValues($qName, 0, $import['collected_by']);
                $query->addMultiInsertValues($qName, 0, 'CURRENT_TIMESTAMP');
                $query->addMultiInsertValues($qName, 0, ''); // expiry

                // Insert into members year - current season
                $query->addMultiInsertValues($qName, 1, 'NULL');
                $query->addMultiInsertValues($qName, 1, $theClient);
                $query->addMultiInsertValues($qName, 1, $memberID);
                $query->addMultiInsertValues($qName, 1, 0);
                $query->addMultiInsertValues($qName, 1, 0); // School
                $query->addMultiInsertValues($qName, 1, 0);
                $query->addMultiInsertValues($qName, 1, -1);
                $query->addMultiInsertValues($qName, 1, 1);
                $query->addMultiInsertValues($qName, 1, '');
                $query->addMultiInsertValues($qName, 1, '-1');
                $query->addMultiInsertValues($qName, 1, '');
                $query->addMultiInsertValues($qName, 1, '');
                $query->addMultiInsertValues($qName, 1, 'CURRENT_TIMESTAMP');
                $query->addMultiInsertValues($qName, 1, ''); // expiry

                buildQuery('multi-insert', $qName);

                $membersToRegister[] = $import['parentBodyID'];

                // Finance - can we remove the $0 filter??
                //if($amount > 0)
                //{
                invoice_handler($theClient, $clientConfig, $clientNationalParent, $financeID, $memberID, $amount);
                //}

                // SECURITY AUDIT - can we optimise this??
                security_audit('c', $memberID, 'member', 'import', '', '', 'h');
            }

            $q = $query->createQuery('update_to_new');
            $query->setClientOverride($q);
            $query->addUpdate($q, 'members_import_api_inbound');
            $query->addUpdateSet($q, 'processed_status', 'register');
            $query->addUpdateSet($q, 'processed_time', 'CURRENT_TIMESTAMP');
            $query->addWhereOrVal($q, 'nationalID', $membersToRegister);
            $query->addWhere($q, 'client_id', $theClient);
            $query->addWhere($q, 'processed_status', '');
            buildQuery('update', $q);
        }

        // 2. Get all members in `members_import_api_inbound` WHERE `nationalID` IN (current database for that sport with season ID = current)
        // i.e. these people are active; disregard
        $q = $query->createQuery('get_current_members_to_exclude');
        $query->setClientOverride($q);
        $query->setSeasonOverride($q);
        $query->addSelect($q, 'parentBodyID', 'members');
        $query->addFrom($q, 'members_year');
        $query->addWhere($q, 'members_year.client_id', $theClient);
        $query->addWhere($q, 'members_year.season_id', $seasonID);
        $query->addWhere($q, 'members.id', 'members_year.member_id', '=', true);
        $subQ2 = prepareQuery('select', $q);

        // Update details
        if(1)
        {
            // get all & update
            $q = $query->createQuery('get_all_existing_members');
            $query->setClientOverride($q);
            $query->setSeasonOverride($q);
            $query->addSelect($q, 'id', 'members');
            $query->addSelect($q, 'nationalID', 'members_import_api_inbound');
            $query->addSelect($q, 'firstname', 'members_import_api_inbound');
            $query->addSelect($q, 'surname', 'members_import_api_inbound');
            $query->addSelect($q, 'dateOfBirth', 'members_import_api_inbound');
            $query->addSelect($q, 'addressStreet', 'members_import_api_inbound');
            $query->addSelect($q, 'addressSuburb', 'members_import_api_inbound');
            $query->addSelect($q, 'addressPostCode', 'members_import_api_inbound');
            $query->addSelect($q, 'addressState', 'members_import_api_inbound');
            $query->addSelect($q, 'addressCountry', 'members_import_api_inbound');
            $query->addSelect($q, 'phoneHome', 'members_import_api_inbound');
            $query->addSelect($q, 'phoneMob', 'members_import_api_inbound');
            $query->addSelect($q, 'email', 'members_import_api_inbound');
            $query->addSelect($q, 'gender', 'members_import_api_inbound');
            $query->addFrom($q, 'members_year');
            $query->addWhere($q, 'members_import_api_inbound.client_id', $theClient);
            $query->addWhere($q, 'processed_status', '');
            $query->addWhere($q, 'members_year.client_id', $theClient);
            $query->addWhere($q, 'members_year.season_id', $seasonID);
            $query->addWhere($q, 'members.id', 'members_year.member_id', '=', true);
            $query->addWhere($q, 'members.parentBodyID', 'members_import_api_inbound.nationalID', '=', true);
            $query->addWhere($q, 'members_import_api_inbound.nationalID', 0, '!=');
            $query->addWhereIn($q, 'nationalID', $subQ2);
            buildQuery('select', $q);

            if($query->rows($q) > 0)
            {
                for ($i = 0; $i < $query->rows($q); $i++)
                {
                    $mID = $query->getResult($q, $i, 'members.id');
                    $nationalID = $query->getResult($q, $i, 'nationalID');
                    $firstname = $query->getResult($q, $i, 'firstname');
                    $surname = $query->getResult($q, $i, 'surname');
                    $dateOfBirth = $query->getResult($q, $i, 'dateOfBirth');
                    $addressStreet = $query->getResult($q, $i, 'addressStreet');
                    $addressSuburb = $query->getResult($q, $i, 'addressSuburb');
                    $addressPostCode = $query->getResult($q, $i, 'addressPostCode');
                    $addressState = $query->getResult($q, $i, 'addressState');
                    $addressCountry = $query->getResult($q, $i, 'addressCountry');
                    $phoneHome = $query->getResult($q, $i, 'phoneHome');
                    $phoneMob = $query->getResult($q, $i, 'phoneMob');
                    $email = $query->getResult($q, $i, 'email');
                    $gender = strtoupper($query->getResult($q, $i, 'gender'));

                    // Create update query
                    $qName = $query->createQuery('update_member_' . $mID);
                    $query->addUpdate($qName, 'members');

                    if($firstname != '')
                    {
                        $query->addUpdateSet($qName, 'firstname', trim($firstname));
                    }

                    if($surname != '')
                    {
                        $query->addUpdateSet($qName, 'surname', trim($surname));
                    }

                    if($firstname != '' && $surname != '')
                    {
                        $query->addUpdateSet($qName, 'lookup_fullname', trim($firstname) . ' ' . trim($surname));
                    }

                    if($dateOfBirth != '')
                    {
                        $query->addUpdateSet($qName, 'dateOfBirth', $dateOfBirth);
                    }

                    if($firstname != '' && $surname != '' && $dateOfBirth != '')
                    {
                        $query->addUpdateSet($qName, 'lookup_fullname_dob', trim($firstname) . ' ' . trim($surname) . ' ' . trim($dateOfBirth));
                    }

                    if($addressStreet != '')
                    {
                        $query->addUpdateSet($qName, 'addressStreet', $addressStreet);
                    }

                    if($addressSuburb != '')
                    {
                        $query->addUpdateSet($qName, 'addressSuburb', $addressSuburb);
                    }

                    if($addressPostCode != '' && $addressPostCode != '0')
                    {
                        $query->addUpdateSet($qName, 'addressPostCode', $addressPostCode);
                    }

                    if($addressState != '')
                    {
                        $query->addUpdateSet($qName, 'addressState', $addressState);
                    }

                    $query->addUpdateSet($qName, 'addressCountry', validate_country($addressCountry));

                    if($phoneHome != '')
                    {
                        $query->addUpdateSet($qName, 'phoneHome', $phoneHome);
                    }

                    if($phoneMob != '')
                    {
                        $query->addUpdateSet($qName, 'phoneMob', $phoneMob);
                    }

                    if($email != '')
                    {
                        $query->addUpdateSet($qName, 'email', $email);
                    }

                    if($gender != '')
                    {
                        $gender = trim(strtoupper($gender));

                        if($gender != 'M' && $gender != 'F' && $gender != 'N' && $gender != 'X')
                        {
                            $gender = 'X';
                        }

                        $query->addUpdateSet($qName, 'gender', $gender);
                    }

                    $query->addWhere($qName, 'id', $mID);
                    $query->addLimit($qName, 1);
                    buildQuery('update', $qName);
                }
            }

            // add update here
            $q = $query->createQuery('update_to_update');
            $query->setClientOverride($q);
            $query->addUpdate($q, 'members_import_api_inbound');
            $query->addUpdateSet($q, 'processed_status', 'update');
            $query->addUpdateSet($q, 'processed_time', 'CURRENT_TIMESTAMP');
            $query->addWhereIn($q, 'nationalID', $subQ2);
            $query->addWhere($q, 'processed_status', '');
            $query->addWhere($q, 'client_id', $theClient);
            buildQuery('update', $q);
            // finish update here

        } // else don't
        else
        {
            // add update here
            $q = $query->createQuery('update_to_skip');
            $query->setClientOverride($q);
            $query->addUpdate($q, 'members_import_api_inbound');
            $query->addUpdateSet($q, 'processed_status', 'skipped');
            $query->addUpdateSet($q, 'processed_time', 'CURRENT_TIMESTAMP');
            $query->addWhereIn($q, 'nationalID', $subQ2);
            $query->addWhere($q, 'processed_status', '');
            $query->addWhere($q, 'client_id', $theClient);
            buildQuery('update', $q);
            // finish update here
        }

        // 3. Get all members in `members_import_api` WHERE `nationalID` IN (current database for that sport with season ID = 0)
        // i.e. by exclusion, these people need to renewed only
        $q = $query->createQuery('get_all_renewing_members');
        $query->setClientOverride($q);
        $query->setSeasonOverride($q);
        $query->addSelect($q, 'id', 'members');
        $query->addSelect($q, 'clubID', 'members_import_api_inbound');
        $query->addSelect($q, 'finance_id', 'members_import_api_inbound');
        $query->addSelect($q, 'payment_method', 'members_import_api_inbound');
        $query->addSelect($q, 'firstname', 'members_import_api_inbound');
        $query->addSelect($q, 'surname', 'members_import_api_inbound');
        $query->addSelect($q, 'dateOfBirth', 'members_import_api_inbound');
        $query->addSelect($q, 'addressStreet', 'members_import_api_inbound');
        $query->addSelect($q, 'addressSuburb', 'members_import_api_inbound');
        $query->addSelect($q, 'addressPostCode', 'members_import_api_inbound');
        $query->addSelect($q, 'addressState', 'members_import_api_inbound');
        $query->addSelect($q, 'addressCountry', 'members_import_api_inbound');
        $query->addSelect($q, 'phoneHome', 'members_import_api_inbound');
        $query->addSelect($q, 'phoneMob', 'members_import_api_inbound');
        $query->addSelect($q, 'email', 'members_import_api_inbound');
        $query->addSelect($q, 'gender', 'members_import_api_inbound');
        $query->addSelect($q, 'amount', 'members_import_api_inbound');
        $query->addWhere($q, 'members_import_api_inbound.client_id', $theClient);
        $query->addWhere($q, 'members.id', 'members_import_api_inbound.renew_id', '=', true); // this should be an exact match
        $query->addFrom($q, 'members_year');

        if($clientNationalParent)
        {
            $tree = explode(',', $clientFamilyTree);
            $count = count($tree);
            $natOwner = $tree[$count-1];

            $query->addWhere($q, 'members_year.client_id', $natOwner);
        }
        else
        {
            $query->addWhere($q, 'members_year.client_id', $theClient);
        }

        $query->addWhere($q, 'members_year.season_id', 0);
        $query->addWhere($q, 'members.id', 'members_year.member_id', '=', true);
        $query->addWhere($q, 'members_import_api_inbound.renew_id', '', '!=');
        $query->addWhere($q, 'processed_status', '');
        buildQuery('select', $q);

        if($query->rows($q) > 0)
        {
            $qIns = $query->createQuery('insert_renewals');
            $query->addMultiInsertTable($qIns, 'members_year');

            $p = 0;

            for ($i = 0; $i < $query->rows($q); $i++)
            {
                // Insert into members year - current season?
                $name = $query->getResult($q, $i, 'firstname').' '.$query->getResult($q, $i, 'surname');

                $mID = $query->getResult($q, $i, 'members.id');
                $clubID = $query->getResult($q, $i, 'clubID');
                $financeID = $query->getResult($q, $i, 'finance_id');
                $paymentMethod = $query->getResult($q, $i, 'payment_method');

                $query->addMultiInsertValues($qIns, $p, 'NULL');
                $query->addMultiInsertValues($qIns, $p, $theClient);
                $query->addMultiInsertValues($qIns, $p, $mID);
                $query->addMultiInsertValues($qIns, $p, $clubID);
                $query->addMultiInsertValues($qIns, $p, '0'); // school id
                $query->addMultiInsertValues($qIns, $p, $seasonID);
                $query->addMultiInsertValues($qIns, $p, $financeID); // finance id
                $query->addMultiInsertValues($qIns, $p, '1'); // approved
                $query->addMultiInsertValues($qIns, $p, ''); // Payment date
                $query->addMultiInsertValues($qIns, $p, $paymentMethod); // Payment method
                $query->addMultiInsertValues($qIns, $p, ''); // Payment receipt
                $query->addMultiInsertValues($qIns, $p, ''); // Payment who
                $query->addMultiInsertValues($qIns, $p, 'CURRENT_TIMESTAMP'); // Creation time
                $query->addMultiInsertValues($qIns, $p, ''); // expiry

                //security_audit('c', $globalUserID, 'member', 'renew', $memberID, '', 'h');

                $firstname = $query->getResult($q, $i, 'firstname');
                $surname = $query->getResult($q, $i, 'surname');
                $dateOfBirth = $query->getResult($q, $i, 'dateOfBirth');
                $addressStreet = $query->getResult($q, $i, 'addressStreet');
                $addressSuburb = $query->getResult($q, $i, 'addressSuburb');
                $addressPostCode = $query->getResult($q, $i, 'addressPostCode');
                $addressState = $query->getResult($q, $i, 'addressState');
                $addressCountry = $query->getResult($q, $i, 'addressCountry');
                $phoneHome = $query->getResult($q, $i, 'phoneHome');
                $phoneMob = $query->getResult($q, $i, 'phoneMob');
                $email = $query->getResult($q, $i, 'email');
                $gender = strtoupper($query->getResult($q, $i, 'gender'));
                $amount = $query->getResult($q, $i, 'amount');

                $addressCountry = validate_country($addressCountry);

                $updates = 0;
                // Create update query
                $qName = $query->createQuery('update_member_' . $mID);
                $query->addUpdate($qName, 'members');

                if($firstname != '')
                {
                    $query->addUpdateSet($qName, 'firstname', trim($firstname));
                    $updates++;
                }

                if($surname != '')
                {
                    $query->addUpdateSet($qName, 'surname', trim($surname));
                    $updates++;
                }

                if($firstname != '' && $surname != '')
                {
                    $query->addUpdateSet($qName, 'lookup_fullname', trim($firstname) . ' ' . trim($surname));
                    $updates++;
                }

                if($dateOfBirth != '')
                {
                    $query->addUpdateSet($qName, 'dateOfBirth', $dateOfBirth);
                    $updates++;
                }

                if($firstname != '' && $surname != '' && $dateOfBirth != '')
                {
                    $query->addUpdateSet($qName, 'lookup_fullname_dob', trim($firstname) . ' ' . trim($surname) . ' ' . trim($dateOfBirth));
                    $updates++;
                }

                if($addressStreet != '')
                {
                    $query->addUpdateSet($qName, 'addressStreet', $addressStreet);
                    $updates++;
                }

                if($addressSuburb != '')
                {
                    $query->addUpdateSet($qName, 'addressSuburb', $addressSuburb);
                    $updates++;
                }

                if($addressPostCode != '' && $addressPostCode != '0')
                {
                    $query->addUpdateSet($qName, 'addressPostCode', $addressPostCode);
                    $updates++;
                }

                if($addressState != '')
                {
                    $query->addUpdateSet($qName, 'addressState', $addressState);
                    $updates++;
                }

                if($addressCountry!= '')
                {
                    $query->addUpdateSet($qName, 'addressCountry', $addressCountry);
                    $updates++;
                }

                if($phoneHome != '')
                {
                    $query->addUpdateSet($qName, 'phoneHome', $phoneHome);
                    $updates++;
                }

                if($phoneMob != '')
                {
                    $query->addUpdateSet($qName, 'phoneMob', $phoneMob);
                    $updates++;
                }

                if($email != '')
                {
                    $query->addUpdateSet($qName, 'email', $email);
                    $updates++;
                }

                if($gender != '')
                {
                    $gender = trim(strtoupper($gender));
                    if($gender != 'M' && $gender != 'F' && $gender != 'N' && $gender != 'X')
                    {
                        $gender = 'X';
                    }

                    $query->addUpdateSet($qName, 'gender', $gender);
                    $updates++;
                }

                $query->addWhere($qName, 'id', $mID);
                $query->addLimit($qName, 1);
                if ($updates > 0) {
                    buildQuery('update', $qName);
                }

                $p++;
            }

            if($p > 0)
            {
                buildQuery('multi-insert', $qIns);
            }

            //Invoice
            if($amount > 0)
            {
                invoice_handler($theClient, $clientConfig, $clientNationalParent, $financeID, $mID, $amount);
            }

            if($clientNationalParent)
            {
                $clientList = explode(',', $clientFamilyTree);

                // check if they have any payment classes at all
                $qClients = $query->createQuery('get_all_clients');
                $query->setClientOverride($qClients);
                $query->addSelect($qClients,'id','clients');
                $query->addSelect($qClients,'parent_id','clients');
                $query->addSelect($qClients,'id','seasons');
                $query->addWhereOrVal($qClients, 'clients.id', $clientList);
                $query->addWhere($qClients, 'clients.id', 'seasons.client_id', '=', true);
                $query->addWhere($qClients, 'seasons.active', 1);
                buildQuery('select',$qClients);

                $allCDetails = array();

                $numR = $query->rows($qClients);

                if($numR > 0)
                {
                    for($j = 0; $j < $numR; $j++)
                    {
                        $sid = $query->getResult($qClients, $j, 'seasons.id');
                        $pid = $query->getResult($qClients, $j, 'parent_id');
                        $cid = $query->getResult($qClients, $j, 'clients.id');
                        $allCDetails[$cid]['current_season'] = $sid;
                        $allCDetails[$cid]['parent_id'] = $pid;
                    }
                }

                $qExistsEverywhere = $query->createQuery('get_all_clients_members');
                $query->setClientOverride($qExistsEverywhere);
                $query->setSeasonOverride($qExistsEverywhere);
                $query->addSelect($qExistsEverywhere,'season_id','members_year');
                $query->addSelect($qExistsEverywhere,'client_id','members_year');
                $query->addWhere($qExistsEverywhere, 'member_id', $mID);
                buildQuery('select',$qExistsEverywhere);

                $allPlaces = array();

                $numR = $query->rows($qExistsEverywhere);

                if($numR > 0)
                {
                    for($j = 0; $j < $numR; $j++)
                    {
                        $sid = $query->getResult($qExistsEverywhere, $j, 'season_id');
                        $cid = $query->getResult($qExistsEverywhere, $j, 'client_id');
                        $allPlaces[$cid][$sid] = true;
                    }
                }

                renewInClientLOL($clientNationalParent, $mID, $allCDetails, $allPlaces, 'w', $theClient, true, '');
            }

            $q = $query->createQuery('update_to_new');
            $query->setClientOverride($q);
            $query->addUpdate($q, 'members_import_api_inbound');
            $query->addUpdateSet($q, 'processed_status', 'renew');
            $query->addUpdateSet($q, 'processed_time', 'CURRENT_TIMESTAMP');
            $query->addWhere($q, 'processed_status', '');
            $query->addWhere($q, 'client_id', $theClient);
            buildQuery('update', $q);
        }
    }
}

?>
