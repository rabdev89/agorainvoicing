<?php

namespace App\Traits;

use App\Model\Order\Invoice;
use App\Model\Product\Product;
use Illuminate\Http\Request;

//////////////////////////////////////////////////////////////////////////////
// PAYMENTS AND EXTRA FUNCTIONALITIES FOR INVOICES
//////////////////////////////////////////////////////////////////////////////

    trait PaymentsAndInvoices
    {
        /*
    *Edit payment Total.
    */
        public function paymentTotalChange(Request $request)
        {
            try {
                $invoice = new Invoice();
                $total = $request->input('total');
                if ($total == '') {
                    $total = 0;
                }
                $paymentid = $request->input('id');
                $creditAmtUserId = $this->payment->where('id', $paymentid)->value('user_id');
                $creditAmt = $this->payment->where('user_id', $creditAmtUserId)
        ->where('invoice_id', '=', 0)->value('amt_to_credit');
                $invoices = $invoice->where('user_id', $creditAmtUserId)->orderBy('created_at', 'desc')->get();
                $cltCont = new \App\Http\Controllers\User\ClientController();
                $invoiceSum = $cltCont->getTotalInvoice($invoices);
                if ($total > $invoiceSum) {
                    $diff = $total - $invoiceSum;
                    $creditAmt = $creditAmt + $diff;
                    $total = $invoiceSum;
                }
                $payment = $this->payment->where('id', $paymentid)->update(['amount'=>$total]);

                $creditAmtInvoiceId = $this->payment->where('user_id', $creditAmtUserId)
        ->where('invoice_id', '!=', 0)->first();
                $invoiceId = $creditAmtInvoiceId->invoice_id;
                $invoice = $invoice->where('id', $invoiceId)->first();
                $grand_total = $invoice->grand_total;
                $diffSum = $grand_total - $total;

                $finalAmt = $creditAmt + $diffSum;
                $updatedAmt = $this->payment->where('user_id', $creditAmtUserId)
        ->where('invoice_id', '=', 0)->update(['amt_to_credit'=>$creditAmt]);
            } catch (\Exception $ex) {
                app('log')->info($ex->getMessage());
                Bugsnag::notifyException($ex);

                return redirect()->back()->with('fails', $ex->getMessage());
            }
        }

        public function doPayment(
        $payment_method,
        $invoiceid,
        $amount,
    $parent_id = '',
        $userid = '',
        $payment_status = 'pending'
    ) {
            try {
                if ($amount > 0) {
                    if ($userid == '') {
                        $userid = \Auth::user()->id;
                    }
                    if ($amount == 0) {
                        $payment_status = 'success';
                    }
                    $this->payment->create([
                    'parent_id'      => $parent_id,
                    'invoice_id'     => $invoiceid,
                    'user_id'        => $userid,
                    'amount'         => $amount,
                    'payment_method' => $payment_method,
                    'payment_status' => $payment_status,
                ]);
                    $this->updateInvoice($invoiceid);
                }
            } catch (\Exception $ex) {
                Bugsnag::notifyException($ex);

                throw new \Exception($ex->getMessage());
            }
        }


        public function getAgents($agents, $productid, $plan)
        {
            if (!$agents) {//If agents is not received in the request in the case when
                // 'modify agent' is not allowed for the Product,get the no of Agents from the Plan Table.
                $planForAgent = Product::find($productid)->planRelation->find($plan);
                if ($planForAgent) {//If Plan Exists For the Product ie not a Product without Plan
                    $noOfAgents = $planForAgent->planPrice->first()->no_of_agents;
                    $agents = $noOfAgents ? $noOfAgents : 0; //If no. of Agents is specified then that,else 0(Unlimited Agents)
                } else {
                    $agents = 0;
                }
            }
            return $agents;
        }

        public function getQuantity($qty, $productid, $plan)
        {
            if (!$qty) {//If quantity is not received in the request in the case when 'modify quantity' is not allowed for the Product,get the Product qUANTITY from the Plan Table.
                $planForQty = Product::find($productid)->planRelation->find($plan);
                if ($planForQty) {
                    $quantity = Product::find($productid)->planRelation->find($plan)->planPrice->first()->product_quantity;
                    $qty = $quantity ? $quantity : 1; //If no. of Agents is specified then that,else 0(Unlimited Agents)
                } else {
                    $qty = 1;
                }
            }
            return $qty;
        }

        public function updateInvoice($invoiceid)
        {
            try {
                $invoice = $this->invoice->findOrFail($invoiceid);
                $payment = $this->payment->where('invoice_id', $invoiceid)
            ->where('payment_status', 'success')->pluck('amount')->toArray();
                $total = array_sum($payment);
                if ($total < $invoice->grand_total) {
                    $invoice->status = 'pending';
                }
                if ($total >= $invoice->grand_total) {
                    $invoice->status = 'success';
                }
                if ($total > $invoice->grand_total) {
                    $user = $invoice->user()->first();
                    $balance = $total - $invoice->grand_total;
                    $user->debit = $balance;
                    $user->save();
                }

                $invoice->save();
            } catch (\Exception $ex) {
                Bugsnag::notifyException($ex);

                throw new \Exception($ex->getMessage());
            }
        }

        public function postRazorpayPayment($invoiceid, $grand_total)
        {
            try {
                $payment_method = \Session::get('payment_method');
                $payment_status = 'success';
                $payment_date = \Carbon\Carbon::now()->toDateTimeString();
                $amount = $grand_total;
                $paymentRenewal = $this->updateInvoicePayment(
                $invoiceid,
                $payment_method,
             $payment_status,
                $payment_date,
                $amount
            );

                return redirect()->back()->with('success', 'Payment Accepted Successfully');
            } catch (\Exception $ex) {
                return redirect()->back()->with('fails', $ex->getMessage());
            }
        }

        public function sendmailClientAgent($userid, $invoiceid)
        {
            try {
                $agent = \Input::get('agent');
                $client = \Input::get('client');
                if ($agent == 1) {
                    $id = \Auth::user()->id;
                    $this->sendMail($id, $invoiceid);
                }
                if ($client == 1) {
                    $this->sendMail($userid, $invoiceid);
                }
            } catch (\Exception $ex) {
                app('log')->info($ex->getMessage());
                Bugsnag::notifyException($ex);

                throw new \Exception($ex->getMessage());
            }
        }

        public function payment(Request $request)
        {
            try {
                if ($request->has('invoiceid')) {
                    $invoice_id = $request->input('invoiceid');
                    $invoice = $this->invoice->find($invoice_id);
                    $userid = $invoice->user_id;
                    //dd($invoice);
                    $invoice_status = '';
                    $payment_status = '';
                    $payment_method = '';
                    $domain = '';
                    if ($invoice) {
                        $invoice_status = $invoice->status;
                        $items = $invoice->invoiceItem()->first();
                        if ($items) {
                            $domain = $items->domain;
                        }
                    }
                    $payment = $this->payment->where('invoice_id', $invoice_id)->first();
                    if ($payment) {
                        $payment_status = $payment->payment_status;
                        $payment_method = $payment->payment_method;
                    }

                    return view(
                    'themes.default1.invoice.payment',
                 compact(
                     'invoice_status',
                     'payment_status',
                  'payment_method',
                     'invoice_id',
                     'domain',
                     'invoice',
                     'userid'
                 )
                );
                }

                return redirect()->back();
            } catch (\Exception $ex) {
                Bugsnag::notifyException($ex);

                return redirect()->back()->with('fails', $ex->getMessage());
            }
        }


        public function deletePayment(Request $request)
        {
            try {
                $ids = $request->input('select');
                if (!empty($ids)) {
                    foreach ($ids as $id) {
                        $payment = $this->payment->where('id', $id)->first();
                        if ($payment) {
                            $invoice = $this->invoice->find($payment->invoice_id);
                            if ($invoice) {
                                $invoice->status = 'pending';
                                $invoice->save();
                            }
                            $payment->delete();
                        } else {
                            echo "<div class='alert alert-danger alert-dismissable'>
                    <i class='fa fa-ban'></i>
                    <b>"./* @scrutinizer ignore-type */ \Lang::get('message.alert').'!</b> 
                    './* @scrutinizer ignore-type */\Lang::get('message.failed').'
                    <button type=button class=close data-dismiss=alert aria-hidden=true>&times;</button>
                        './* @scrutinizer ignore-type */\Lang::get('message.no-record').'
                </div>';
                            //echo \Lang::get('message.no-record') . '  [id=>' . $id . ']';
                        }
                    }
                    echo "<div class='alert alert-success alert-dismissable'>
                    <i class='fa fa-ban'></i>
                    <b>"./* @scrutinizer ignore-type */\Lang::get('message.alert').'!</b> '.
                    /* @scrutinizer ignore-type */
                    \Lang::get('message.success').'
                    <button type=button class=close data-dismiss=alert aria-hidden=true>&times;</button>
                        './* @scrutinizer ignore-type */\Lang::get('message.deleted-successfully').'
                </div>';
                } else {
                    echo "<div class='alert alert-danger alert-dismissable'>
                    <i class='fa fa-ban'></i>
                    <b>"./* @scrutinizer ignore-type */\Lang::get('message.alert').'!</b> '.
                    /* @scrutinizer ignore-type */\Lang::get('message.failed').'
                    <button type=button class=close data-dismiss=alert aria-hidden=true>&times;</button>
                        './* @scrutinizer ignore-type */\Lang::get('message.select-a-row').'
                </div>';
                    //echo \Lang::get('message.select-a-row');
                }
            } catch (\Exception $e) {
                Bugsnag::notifyException($e);
                echo "<div class='alert alert-danger alert-dismissable'>
                    <i class='fa fa-ban'></i>
                    <b>"./* @scrutinizer ignore-type */\Lang::get('message.alert').'!</b> '.
                    /* @scrutinizer ignore-type */ \Lang::get('message.failed').'
                    <button type=button class=close data-dismiss=alert aria-hidden=true>&times;</button>
                        '.$e->getMessage().'
                </div>';
            }
        }

        public function pdf(Request $request)
        {
            try {
                $id = $request->input('invoiceid');
                if (!$id) {
                    return redirect()->back()->with('fails', \Lang::get('message.no-invoice-id'));
                }
                $invoice = $this->invoice->where('id', $id)->first();
                if (!$invoice) {
                    return redirect()->back()->with('fails', \Lang::get('message.invalid-invoice-id'));
                }
                $invoiceItems = $this->invoiceItem->where('invoice_id', $id)->get();
                if ($invoiceItems->count() == 0) {
                    return redirect()->back()->with('fails', \Lang::get('message.invalid-invoice-id'));
                }
                $user = $this->user->find($invoice->user_id);
                if (!$user) {
                    return redirect()->back()->with('fails', 'No User');
                }
                $cont = new \App\Http\Controllers\Front\CartController();
                $currency = $cont->currency($user->id);
                $symbol =  $currency['currency'];
                $pdf = \PDF::loadView('themes.default1.invoice.newpdf', compact('invoiceItems', 'invoice', 'user', 'currency', 'symbol'));
                return $pdf->download($user->first_name.'-invoice.pdf');
            } catch (\Exception $ex) {
                Bugsnag::notifyException($ex);

                return redirect()->back()->with('fails', $ex->getMessage());
            }
        }
    }