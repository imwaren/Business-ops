"""Render procurement master posting from clinic lifecycle, preserving existing master hooks."""
from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[2]
OUT=Path(__file__).parent
clinic=(ROOT/'sql/general_ledger_triggers.sql').read_text()
def extract(kind,name):
    return re.search(r'CREATE '+kind+' '+name+r'\(.*?END\$\$',clinic,re.S).group()
posting='-- Shared procurement only: master accounting at 0. No backfill.\nDELIMITER $$\n'
for name in ['gl_rn_vat_type','gl_rn_net','gl_rn_vat_amt']:
    posting+='DROP FUNCTION IF EXISTS '+name+'$$\n'+extract('FUNCTION',name)+'\n'
for table in ['purchase_request','purchase_order','receipt_note']:
    name='gl_shared_post_'+table
    routine=extract('PROCEDURE','gl_post_'+table).replace('gl_post_'+table,name,1)
    # Declarations must stay before executable guards.
    guard=f"    IF NOT EXISTS (SELECT 1 FROM {table} s JOIN branch b ON b.id=s.branch_id WHERE s.id=p_id AND b.type='shared' AND b.is_deleted=0) THEN LEAVE proc; END IF;\n"
    at=routine.index('    OPEN item_cursor;')
    routine=routine[:at]+guard+routine[at:]
    routine=routine.replace('BEGIN\n','proc: BEGIN\n',1).replace('gl_branch_id()', '0').replace('CALL gl_clear(', 'CALL gl_shared_clear(')
    routine=routine.replace('CALL gl_post_purchase_order(', 'CALL gl_shared_post_purchase_order(').replace('CALL gl_post_purchase_request(', 'CALL gl_shared_post_purchase_request(')
    routine=routine.replace('mytemr_master.item','item')
    if table=='receipt_note':
        routine=routine[:-len('END$$')]+'''    BEGIN
        DECLARE done INT DEFAULT 0;
        DECLARE cid INT;
        DECLARE c CURSOR FOR SELECT id FROM receipt_note_item WHERE receipt_note_id=p_id;
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET done=1;
        OPEN c;
        journals: LOOP
            FETCH c INTO cid;
            IF done=1 THEN LEAVE journals; END IF;
            CALL gl_shared_je('receipt_note_item',cid);
        END LOOP;
        CLOSE c;
    END;
END$$'''
    posting+='DROP PROCEDURE IF EXISTS '+name+'$$\n'+routine+'\n'
for table in ['purchase_request','purchase_order','receipt_note']:
    for suffix,event in [('ai','INSERT'),('au','UPDATE')]:
        name='trg_gl_shared_'+table+'_'+suffix
        posting+=f'DROP TRIGGER IF EXISTS {name}$$\nCREATE TRIGGER {name} AFTER {event} ON {table}\nFOR EACH ROW BEGIN CALL gl_shared_post_{table}(NEW.id); END$$\n'
for table,parent in [('purchase_request_item','purchase_request'),('purchase_order_item','purchase_order'),('receipt_note_item','receipt_note')]:
    for suffix,event,row in [('ai','INSERT','NEW'),('au','UPDATE','NEW'),('ad','DELETE','OLD')]:
        name='trg_gl_shared_'+table+'_'+suffix
        body=f'CALL gl_shared_post_{parent}({row}.{parent}_id);'
        if event=='UPDATE': body+=f' IF OLD.{parent}_id<>NEW.{parent}_id THEN CALL gl_shared_post_{parent}(OLD.{parent}_id); END IF;'
        # Deleted children must retire their historical source too.
        if event=='DELETE': body=f"CALL gl_shared_clear('{table}',OLD.id); "+body
        posting+=f'DROP TRIGGER IF EXISTS {name}$$\nCREATE TRIGGER {name} AFTER {event} ON {table}\nFOR EACH ROW BEGIN {body} END$$\n'
posting+='DELIMITER ;\n'
(OUT/'posting.sql').write_text(posting)
rollback=['-- Restore exact prior definitions after removing release helpers/hooks. Retain additive schema.']
for kind in ['TRIGGER','PROCEDURE','FUNCTION']:
    for name in re.findall(r'CREATE '+kind+r' (\w+)',posting): rollback.append(f'DROP {kind} IF EXISTS {name};')
(OUT/'rollback.sql').write_text('\n'.join(rollback)+'\n')
assertion='''DELIMITER $$
DROP PROCEDURE IF EXISTS assert_shared_procurement_install$$
CREATE PROCEDURE assert_shared_procurement_install()
BEGIN
 IF DATABASE() NOT IN ('mytemr_master','mytemr_master_uat') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Shared procurement requires the selected master database';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()
  AND ROUTINE_NAME IN ('gl_shared_clear','gl_shared_je','gl_account_id','gl_post_apv'))<>4 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Install current shared-fund accounting prerequisites first';
 END IF;
 IF (SELECT COUNT(*) FROM account_type WHERE code IN ('104-030','106-000','201-110','201-111') AND status='active' AND is_deleted=0)<>4 OR (SELECT COUNT(DISTINCT code) FROM account_type WHERE code IN ('104-030','106-000','201-110','201-111') AND status='active' AND is_deleted=0)<>4 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing procurement inventory/VAT/transitory/payable account mappings';
 END IF;
 IF EXISTS (SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()
  AND EVENT_OBJECT_TABLE IN ('purchase_request','purchase_request_item','purchase_order','purchase_order_item','receipt_note','receipt_note_item')
  AND ACTION_STATEMENT REGEXP 'gl_post_|gl_shared_post_'
  AND TRIGGER_NAME NOT LIKE 'trg_gl_shared_%') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conflicting existing procurement posting hooks; reconcile before installation';
 END IF;
END$$
CALL assert_shared_procurement_install()$$
DROP PROCEDURE assert_shared_procurement_install$$
DELIMITER ;
'''
(OUT/'assert.sql').write_text(assertion)
(OUT/'install.sql').write_text(assertion+'\n'+(OUT/'schema.sql').read_text()+'\n'+posting)
marker='-- BEGIN SHARED PROCUREMENT ACCOUNTING (generated by sql/shared_procurement/build.py)'
for suffix in ['', '_uat']:
    p=ROOT/f'sql/general_ledger_triggers_master{suffix}.sql'
    text=p.read_text().split(marker)[0].rstrip()
    p.write_text(text+'\n\n'+marker+'\n'+(OUT/'schema.sql').read_text()+'\n'+posting)
