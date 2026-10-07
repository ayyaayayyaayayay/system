from pathlib import Path
import re, json
from playwright.sync_api import sync_playwright
root=Path.cwd()
html=(root/'html/adminpanel.html').read_text(encoding='utf-8')
html=re.sub(r'<script\b[^>]*>.*?</script>', '', html, flags=re.S)
html=re.sub(r'<link[^>]+rel="stylesheet"[^>]*>', '', html)
html=html.replace('<head>', '<head><style>'+ '\n'.join((root/f).read_text(encoding='utf-8') for f in ['css/hrpanel.css','css/panel-theme.css','css/adminpanel.css'])+'</style>')
source=(root/'JsScrip/adminpanel.js').read_text(encoding='utf-8')
def extract(start,end):
 return source[source.index(start):source.index(end,source.index(start))]
handlers='let adminUserCardActionsBound=false; window.testActions=[]; function editUser(id){testActions.push("edit:"+id)} function toggleUserStatus(btn,email){testActions.push("status:"+email)}'
handlers+=extract('function setupOrganizationStructure()', '/**\n * Campus filter')
handlers+=extract('function wireDynamicDepartmentToggles()', 'function setupUserSearch()')
handlers+=extract('function setupUserCardActions()', 'function toggleEditUserStudentFields(')
with sync_playwright() as p:
 browser=p.chromium.launch()
 for width,height in [(360,800),(496,800),(844,390),(1440,1000)]:
  page=browser.new_page(viewport={'width':width,'height':height},is_mobile=width<1000,has_touch=True)
  page.set_content(html,wait_until='load')
  page.add_script_tag(content=handlers+'; setupOrganizationStructure(); setupUserCardActions();')
  page.evaluate('''() => {
   document.querySelectorAll('.content-view').forEach(x => x.style.display='none');
   const view=document.querySelector('#users-view');view.style.display='flex';view.style.flexDirection='column';
   const cards=Array.from({length:12},(_,i)=>'<div class="user-card-compact"><div class="user-details"><div class="name">Test User '+i+'</div></div><div class="user-actions-compact"><button class="edit-btn" data-user-id="'+i+'">Edit</button><button class="status-btn active" data-user-email="user'+i+'@example.test">ACTIVE</button></div></div>').join('');
   document.querySelector('#hr-user-list').innerHTML=cards;
   document.querySelector('#departments-dynamic').innerHTML='<div class="department-block"><div class="dept-header open" data-toggle="demo"><h4>ILAS</h4></div><div class="dept-content open" id="demo"><div class="role-group"><div class="role-header open" data-toggle="demo-users"><span>Deans</span></div><div class="role-users open" id="demo-users">'+cards+'</div></div></div></div>';
  }''')
  page.evaluate('wireDynamicDepartmentToggles()')
  def tap(selector):
   loc=page.locator(selector)
   loc.scroll_into_view_if_needed()
   box=loc.bounding_box()
   assert box
   assert loc.evaluate('e => {const r=e.getBoundingClientRect(); return e.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2))}'),selector+' tap blocked'
   page.touchscreen.tap(box['x']+box['width']/2,box['y']+box['height']/2)
  for target in ['hr-users','vpaa-users','osa-users','admin-users']:
   tap('.org-header[data-toggle="'+target+'"]')
   assert page.locator('#'+target).evaluate('e => e.classList.contains("open")')
   if target=='hr-users':
    tap('#hr-user-list .edit-btn[data-user-id="11"]')
    tap('#hr-user-list .status-btn[data-user-email="user11@example.test"]')
   tap('.org-header[data-toggle="'+target+'"]')
   assert not page.locator('#'+target).evaluate('e => e.classList.contains("open")')
  tap('.dept-header[data-toggle="demo"]')
  assert not page.locator('#demo').evaluate('e => e.classList.contains("open")')
  tap('.dept-header[data-toggle="demo"]')
  tap('.role-header[data-toggle="demo-users"]')
  assert not page.locator('#demo-users').evaluate('e => e.classList.contains("open")')
  tap('.role-header[data-toggle="demo-users"]')
  tap('#demo-users .edit-btn[data-user-id="11"]')
  tap('#demo-users .status-btn[data-user-email="user11@example.test"]')
  assert page.evaluate('testActions')==['edit:11','status:user11@example.test']*2
  assert page.evaluate('''() => Array.from(document.querySelectorAll('.organization-structure > .org-section')).every(e => {
   const r=e.getBoundingClientRect(), h=e.querySelector('.org-header').getBoundingClientRect();return h.bottom<=r.bottom;
  })'''), 'Header clipped by its section'
  tap('#add-user-btn')
  if width==496:
   page.locator('#user-search-btn').scroll_into_view_if_needed()
   page.screenshot(path=str(root/'.codex/admin-users-mobile-after.png'))
  print(json.dumps({'viewport':[width,height],'group_taps':'passed','last_user_edit_and_status':'passed','add_user_tap':'passed'}))
  page.close()
 browser.close()

