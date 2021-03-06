<?php

namespace App\Pages\Report;

use App\Entity\Item;
use App\Helper as H;
use Zippy\Html\Form\Date;
use Zippy\Html\Form\DropDownChoice;
use Zippy\Html\Form\Form;
use Zippy\Html\Label;
use Zippy\Html\Link\RedirectLink;
use Zippy\Html\Panel;

/**
 * Отчет по продажам
 */
class Outcome extends \App\Pages\Base
{

    public function __construct() {
        parent::__construct();
        if (false == \App\ACL::checkShowReport('Outcome')) {
            return;
        }
        $br = "";
        $brids = \App\ACL::getBranchIDsConstraint();
        if (strlen($brids) > 0) {
            $br = " and  branch_id in ({$brids}) ";
        }

        $this->add(new Form('filter'))->onSubmit($this, 'OnSubmit');
        $this->filter->add(new Date('from', time() - (7 * 24 * 3600)));
        $this->filter->add(new Date('to', time()));
        $this->filter->add(new DropDownChoice('emp', \App\Entity\User::findArray('username', "user_id in (select user_id from documents_view  where  meta_name  in('GoodsIssue','ServiceAct','Task','Order','POSCheck','TTN')  {$br}  )", 'username'), 0));
        $this->filter->add(new DropDownChoice('cat', \App\Entity\Category::findArray('cat_name', "", 'cat_name'), 0))->setVisible(false);
        $hlist = \App\Entity\Customer::getHoldList();
        //  $this->filter->add(new DropDownChoice('holding', $hlist, 0))->setVisible(false);


        $types = array();
        $types[1] = H::l('repbyitems');
        $types[6] = H::l('repbybyersitem');
        $types[2] = H::l('repbybyers');
        $types[3] = H::l('repbydates');
        $types[4] = H::l('repbyservices');
        $types[7] = H::l('repbybyersservices');
        $types[5] = H::l('repbycat');
        if (count($hlist) > 0) {
            $types[8] = H::l('repbyhold');
        }


        $this->filter->add(new DropDownChoice('type', $types, 1))->onChange($this, "OnType");

        $this->filter->add(new \Zippy\Html\Form\AutocompleteTextInput('cust'))->onText($this, 'OnAutoCustomer');
        $this->filter->cust->setVisible(false);

        $this->add(new Panel('detail'))->setVisible(false);
        $this->detail->add(new \Zippy\Html\Link\BookmarkableLink('print', ""));
        $this->detail->add(new RedirectLink('word', "outcome"));
        $this->detail->add(new RedirectLink('excel', "outcome"));
        $this->detail->add(new RedirectLink('pdf', "outcome"));
        $this->detail->add(new Label('preview'));
    }

    public function OnType($sender) {
        $type = $this->filter->type->getValue();
        $this->filter->cat->setValue(0);

        $this->filter->cat->setVisible($type == 5);
        $this->filter->cust->setVisible($type == 6 || $type == 7);
        //  $this->filter->holding->setVisible($type == 7);
    }

    public function OnAutoItem($sender) {
        $r = array();


        $text = Item::qstr('%' . $sender->getText() . '%');
        $list = Item::findArray('itemname', " disabled <> 1  and (itemname like {$text} or item_code like {$text} ) ");
        foreach ($list as $k => $v) {
            $r[$k] = $v;
        }
        return $r;
    }

    public function OnSubmit($sender) {


        $html = $this->generateReport();
        $this->detail->preview->setText($html, true);
        \App\Session::getSession()->printform = "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"></head><body>" . $html . "</body></html>";

        // \ZippyERP\System\Session::getSession()->storereport = "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"></head><body>" . $html . "</body></html>";
        $reportpage = "App/Pages/ShowReport";
        $reportname = "outcome";


        $this->detail->word->pagename = $reportpage;
        $this->detail->word->params = array('doc', $reportname);
        $this->detail->excel->pagename = $reportpage;
        $this->detail->excel->params = array('xls', $reportname);
        $this->detail->pdf->pagename = $reportpage;
        $this->detail->pdf->params = array('pdf', $reportname);

        $this->detail->setVisible(true);
    }

    public function OnAutoCustomer($sender) {
        $text = \App\Entity\Customer::qstr('%' . $sender->getText() . '%');
        return \App\Entity\Customer::findArray("customer_name", "status=0 and (customer_name like {$text}  or phone like {$text} )");
    }


    private function generateReport() {
        $conn = \ZDB\DB::getConnect();
        $type = $this->filter->type->getValue();
        $user = $this->filter->emp->getValue();
        $cat_id = $this->filter->cat->getValue();
        //   $hold_id = $this->filter->holding->getValue();
        $cust_id = $this->filter->cust->getKey();

        $from = $this->filter->from->getDate();
        $to = $this->filter->to->getDate();


        $u = "";

        if ($user > 0) {
            $u = " and d.user_id={$user} ";
        }
        $br = "";
        $brids = \App\ACL::getBranchIDsConstraint();
        if (strlen($brids) > 0) {
            $br = " and d.branch_id in ({$brids}) ";
        }

        // скидка


        $sql = "document_id in( select  d.document_id  from  
               `documents_view` d  
               where    d.state >3 and 
                 d.`meta_name` in ('GoodsIssue', 'Order',  'POSCheck')
               {$br}  {$u}     and  d.payamount >0 and  d.payamount <  d.amount 
              AND DATE(d.document_date) >= " . $conn->DBDate($from) . "
              AND DATE(d.document_date) <= " . $conn->DBDate($to) . ")";

        $res = \App\Entity\Doc\Document::find($sql);
        $disc = 0;
        foreach ($res as $d) {
            if ($d->headerdata['paydisc'] > 0) {

                $disc += $d->headerdata['paydisc'];
            }
        }


        $detail = array();

        $cat = "";
        if ($type == 5 && $cat_id > 0) {
            $cat = " and cat_id=" . $cat_id;
        }
        $cust = "";


        if (($type == 6 || $type == 7) && $cust_id > 0) {
            $cust = " and d.customer_id=" . $cust_id;
            $c = \App\Entity\Customer::load($cust_id);
            if ($c->isholding == 1) {
                $list = \App\Entity\Customer::find("detail   like '%<holding>{$cust_id}</holding>%' ");
                $ids = array_keys($list);
                if (count($ids) > 0) {
                    $cust = " and d.customer_id  in(" . implode(',', $ids) . "  )";
                }


            }
        }

        $sql = '';
        if ($type == 1 || $type == 6 || strlen($cat) > 0) {    //по товарам
            $sql = "
          select i.`itemname`,i.`item_code`,sum(0-e.`quantity`) as qty, sum(0-e.`amount`) as summa, sum(e.extcode*(0-e.`quantity`)) as navar
              from `entrylist_view`  e

              join `items_view` i on e.`item_id` = i.`item_id`
             join `documents_view` d on d.`document_id` = e.`document_id`
               where e.`item_id` >0  and e.`quantity` <> 0   {$cat}   {$cust}  
               and d.`meta_name` in ('GoodsIssue', 'POSCheck','ReturnIssue','TTN')
               {$br}  {$u}
              AND DATE(e.document_date) >= " . $conn->DBDate($from) . "
              AND DATE(e.document_date) <= " . $conn->DBDate($to) . "
                group by  i.`itemname`,i.`item_code`
               order  by i.`itemname`
        ";
        }
        if ($type == 2) {  //по покупателям
            $empty = H::l("emptycust");
            $sql = "
          select coalesce(c.`customer_name`,'{$empty}') as itemname,c.`customer_id`,  sum(0-e.`amount`) as summa, sum(e.extcode*(0-e.`quantity`)) as navar
          from `entrylist_view`  e

        left  join `customers`  c on c.`customer_id` = e.`customer_id`
         join `documents_view`  d on d.`document_id` = e.`document_id`
           where   e.`quantity` <>0       
             and d.`meta_name` in ('GoodsIssue',    'POSCheck','ReturnIssue','TTN')         AND DATE(e.document_date) >= " . $conn->DBDate($from) . "
              {$br} {$u}   AND DATE(e.document_date) <= " . $conn->DBDate($to) . "
             AND c.detail not like '%<isholding>1</isholding>%'               
          group by  c.`customer_name`,c.`customer_id`
          order  by c.`customer_name`
        ";
        }
        if ($type == 3) {   //по датам
            $sql = "
          select e.`document_date` as dt  ,  sum(0-e.`amount`) as summa    ,0 as navar
              from `entrylist_view`  e

              join `items` i on e.`item_id` = i.`item_id`
             join `documents_view` d on d.`document_id` = e.`document_id`
               where e.`item_id` >0  and e.`quantity` <>0
              and d.`meta_name` in ('GoodsIssue','ServiceAct' ,'POSCheck','ReturnIssue','TTN')           
               {$br} {$u} AND DATE(e.document_date) >= " . $conn->DBDate($from) . "
              AND DATE(e.document_date) <= " . $conn->DBDate($to) . "
         group by  e.`document_date`
  order  by e.`document_date`
        ";
        }

        if ($type == 4 || $type == 7) {    //по сервисам
            $sql = "
         select s.`service_name` as itemname, sum(0-e.`quantity`) as qty, sum(0-e.`amount`) as summa    ,0 as navar
              from `entrylist_view`  e

              join `services` s on e.`service_id` = s.`service_id`
             join `documents_view` d on d.`document_id` = e.`document_id`
               where e.`service_id` >0  and e.`quantity` <>0      {$cust}  
              and d.`meta_name` in (  'ServiceAct' ,'POSCheck' )
               {$br} {$u} AND DATE(e.document_date) >= " . $conn->DBDate($from) . "
              AND DATE(e.document_date) <= " . $conn->DBDate($to) . "
                   group by s.`service_name`
               order  by s.`service_name`      ";
        }

        if ($type == 5 && strlen($cat) == 0) {    //по категориях
            $sql = "
            select  i.`cat_name` as itemname,sum(0-e.`quantity`) as qty, sum(0-e.`amount`) as summa, sum(e.extcode*(0-e.`quantity`)) as navar
              from `entrylist_view`  e

              join `items_view` i on e.`item_id` = i.`item_id`
             join `documents_view` d on d.`document_id` = e.`document_id`
               where e.`item_id` >0  and e.`quantity` <>0
               and d.`meta_name` in ('GoodsIssue', 'POSCheck','ReturnIssue','TTN')
                {$br} {$u}
              AND DATE(e.document_date) >= " . $conn->DBDate($from) . "
              AND DATE(e.document_date) <= " . $conn->DBDate($to) . "
                group by    i.`cat_name`
               order  by i.`cat_name`
        ";
        }


        if ($type == 8) {  //по холдингам
            $sql = '';
            $rs = array();

            $hlist = \App\Entity\Customer::getHoldList();
            foreach ($hlist as $id => $name) {
                $custlist = '0';
                $list = \App\Entity\Customer::find("detail   like '%<holding>{$id}</holding>%' ");
                $ids = array_keys($list);
                if (count($ids) > 0) {
                    $custlist = implode(',', $ids);
                }


                $sqlc = "
                  select    coalesce(sum(0-e.`amount`) ,0) as summa, sum(e.extcode*(0-e.`quantity`)) as navar
                  from `entrylist_view`  e

               
                 join `documents_view`  d on d.`document_id` = e.`document_id`
                   where     e.`quantity` <>0 
                     and d.`meta_name` in ('GoodsIssue', 'ServiceAct' , 'POSCheck','ReturnIssue','TTN')    
                      {$br} {$u}  AND DATE(e.document_date) >= " . $conn->DBDate($from) . "
                      AND DATE(e.document_date) <= " . $conn->DBDate($to) . "
                      and d.customer_id in({$custlist})
                ";

                $row = $conn->GetRow($sqlc);
                if ($row['summa'] <> 0) {
                    $row['itemname'] = $name;
                    $rs[] = $row;
                }


            }

        }


        $totsum = 0;
        $totnavar = 0;

        if (strlen($sql) > 0) {
            $rs = $conn->Execute($sql);
        }


        foreach ($rs as $row) {

            // $summa = $row['summa'];
            //  if ($row['navar'] != 0) {
            //      $row['summa'] += $row['navar'];
            //  }


            $detail[] = array(
                "code"      => $row['item_code'],
                "name"      => $row['itemname'],
                "dt"        => \App\Helper::fd(strtotime($row['dt'])),
                "qty"       => H::fqty($row['qty']),
                "navar"     => H::fa($row['navar']),
                "navarsign" => $row['navar'] > 0,
                "summa"     => H::fa($row['summa'] + $row['navar'])
            );

            $totnavar += $row['navar'];
            $totsum += ($row['summa'] + $row['navar']);
        }

        $header = array('datefrom' => \App\Helper::fd($from),
                        "_detail"  => $detail,
                        'dateto'   => \App\Helper::fd($to)
        );

        $header['totsumma'] = H::fa($totsum);
        $header['totnavar'] = H::fa($totnavar);
        $header['disc'] = H::fa($disc);
        $header['isdisc'] = $disc > 0;
        $header['totall'] = H::fa($totsum - $disc);


        if ($type == 1 || $type == 6 || strlen($cat) > 0) {
            $header['_type1'] = true;
            $header['_type2'] = false;
            $header['_type3'] = false;
            $header['_type4'] = false;
            $header['_type5'] = false;
        }
        if ($type == 2 || $type == 8) {
            $header['_type1'] = false;
            $header['_type2'] = true;
            $header['_type3'] = false;
            $header['_type4'] = false;
            $header['_type5'] = false;
        }
        if ($type == 3) {
            $header['_type1'] = false;
            $header['_type2'] = false;
            $header['_type3'] = true;
            $header['_type4'] = false;
            $header['_type5'] = false;
        }
        if ($type == 4 || $type == 7) {
            $header['_type1'] = false;
            $header['_type2'] = false;
            $header['_type3'] = false;
            $header['_type4'] = true;
            $header['_type5'] = false;
        }
        if ($type == 5 && strlen($cat) == 0) {
            $header['_type1'] = false;
            $header['_type2'] = false;
            $header['_type3'] = false;
            $header['_type4'] = false;
            $header['_type5'] = true;
        }


        $report = new \App\Report('report/outcome.tpl');

        $html = $report->generate($header);

        return $html;
    }

}
