"""Static checks only: never executes collection requests."""
import json,re
from pathlib import Path
collection=json.loads(Path('docs/postman/Items_blindsheet.postman_collection.json').read_text())
variables={row['key']:row['value'] for row in collection['variable']}
assert all(variables[key]=='' for key in ['api_key','token','requester'])
assert collection['info']['schema'].endswith('/v2.1.0/collection.json')
assert 'event' not in collection
assert len(collection['item'])==7
endpoints=set();responses=0
for item in collection['item']:
    assert 'event' not in item
    request=item['request']; endpoint='/'.join(request['url']['path']);endpoints.add(endpoint)
    assert request['method'] in ['GET','POST']
    assert request['url']['raw'].startswith('{{base_url}}/'+endpoint)
    assert any(header['key']=='API-KEY' and header['value']=='{{api_key}}' for header in request['header'])
    fields=request['url']['query'] if request['method']=='GET' else request['body']['formdata']
    assert all(key in {field['key'] for field in fields} for key in ['branch_id','requester','token','origin'])
    if request['method']=='POST':
        assert request['body']['mode']=='formdata'
        assert all(header['key'].lower()!='content-type' for header in request['header'])
        required=['blindsheet_id','status'] if endpoint.endswith('change_status') else ['branch_item_id','item_id','physical_count','prev_qty','date_counted']
        assert all(any(field['key']==key and not field.get('disabled') for field in fields) for key in required)
    for response in item['response']:
        assert 'illustrative' in response['name'];json.loads(response['body']);responses+=1
    for name in re.findall(r'\{\{(\w+)\}\}',json.dumps(item)):
        assert name in variables,name
assert endpoints=={'items/get_blindsheet','items/blindsheet','items/blindsheet_change_status'}
assert 'http' not in variables['api_key']
print(f'PASS: 7 Postman requests, {responses} saved response JSON examples, variables, headers, multipart fields, blank credentials, and no auto-execution.')
