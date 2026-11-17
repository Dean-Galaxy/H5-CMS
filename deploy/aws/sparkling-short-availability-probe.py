
import urllib.error
import urllib.request
import boto3

class RejectRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):
        return None

http=urllib.request.build_opener(RejectRedirect())
metrics=boto3.client('cloudwatch')
base='https://sparklingshort.com'

def lambda_handler(event, context):
    drill=event.get('drill') is True
    path='/__h5-availability-controlled-missing-probe' if drill else '/identity/login'
    request=urllib.request.Request(base+path,headers={'Accept-Encoding':'identity','Cache-Control':'no-cache','User-Agent':'H5-availability/1'})
    try:
        with http.open(request,timeout=6) as response:
            reachable=response.status==200 and b'data-identity-form' in response.read(5120)
    except (OSError, ValueError):
        reachable=False
    metrics.put_metric_data(Namespace='H5/SparklingShort',MetricData=[{
        'MetricName':'PublicLoginReachable','Unit':'Count','Value':int(reachable),
        'Dimensions':[{'Name':'Environment','Value':'production'}],
    }])
    return {'reachable':reachable,'drill':drill}
